# Issue 19 — `LocationProvider`: повторные запросы к БД для одних и тех же стран/регионов

**Статус:** ⬜ Открыта
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

## Рекомендация

Добавить статические кэши — страны и регионы не меняются в рамках одного запроса:

```php
private static array $country_cache = [];
private static array $region_cache = [];

public function getCountryName($country): ?string
{
    return self::$country_cache[$country] ??= $this->getCountryModel()->name($country);
}

public function getRegionName($country, $region)
{
    $key = $country . ':' . $region;
    if (!isset(self::$region_cache[$key])) {
        $data = $this->getRegionModel()->get($country, $region);
        self::$region_cache[$key] = ifset($data, 'name', null);
    }
    return self::$region_cache[$key];
}
```
