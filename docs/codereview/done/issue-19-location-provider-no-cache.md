# Issue 19 — `LocationProvider`: повторные запросы к БД для одних и тех же стран/регионов

**Статус:** ✅ Закрыта
**Приоритет:** 🟠 Высокий  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `location/shopPrefillPluginLocationProvider.class.php`

## Проблема

`getCountryName()` и `getRegionName()` вызываются в цикле при построении коллекции `getFillParamsCollection`. При 10 заказах в одну страну — 10 одинаковых запросов к таблице `wa_country`.

```php
// Без кэша: каждый вызов → SQL
public function getCountryName($country): ?string
{
    return $this->getCountryModel()->name($country); // SELECT FROM wa_country WHERE iso3=?
}

public function getRegionName($country, $region)
{
    $region = $this->getRegionModel()->get($country, $region); // SELECT FROM wa_region WHERE ...
    return ifset($region, 'name', null);
}
```

## Решение

В `shopPrefillPluginLocationProvider` добавлен request-scope кэш:

- `self::$country_name_cache` для названий стран
- `self::$region_name_cache` для названий регионов (`country:region`)

`getCountryName()` и `getRegionName()` теперь сначала читают данные из кэша и обращаются к БД только при первом запросе конкретного ключа.  
Для корректной обработки значений `null` используется `array_key_exists`, чтобы отсутствие названия тоже кэшировалось и не вызывало повторные SQL-запросы.
