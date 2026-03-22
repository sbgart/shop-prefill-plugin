# Issue 18 — `getFillParams()` не кэшируется внутри провайдера

**Статус:** ⬜ Открыта  
**Приоритет:** 🟠 Высокий  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `fillparams/shopPrefillPluginFillParamsProvider.class.php`

## Проблема

`getFillParams()` выполняет SQL-запросы при каждом вызове. Метод вызывается минимум дважды в хуке `frontendHead`:

```php
// hooks/shopPrefillPluginFrontendHooks.class.php
$fill_params = $this->fill_params_provider->getFillParams(); // SQL: getLastOrderIdByContactId + getOrderParams
// ...
$this->session_storage->preFillCheckoutParams($fill_params);
```

И повторно в `checkoutBeforeAuth` при каждом AJAX-запросе чекаута.  
Нет никакого per-request кэша — идентичные данные загружаются заново.

## Рекомендация

Добавить мемоизацию в `FillParamsProvider`:

```php
private ?shopPrefillPluginFillParams $fill_params_cache = null;

public function getFillParams(?int $fill_params_id = null): shopPrefillPluginFillParams
{
    // Кэшируем только дефолтный вызов (без конкретного ID)
    if ($fill_params_id === null) {
        return $this->fill_params_cache ??= $this->resolveFillParams(null);
    }
    return $this->resolveFillParams($fill_params_id);
}
```
