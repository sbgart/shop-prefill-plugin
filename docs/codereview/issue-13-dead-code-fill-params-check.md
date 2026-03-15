# Issue 13 — `checkoutBeforeAuth` — проверяет `null`, но метод никогда не возвращает `null`

**Статус:** ⬜ Открыта  
**Приоритет:** 🟢 Косметика  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `hooks/shopPrefillPluginCheckoutHooks.class.php`, строки 53–56

## Проблема

`getFillParams()` объявлен с типом `: shopPrefillPluginFillParams` — всегда возвращает объект. Проверка `if (!$fill_params)` — мёртвый код.

```php
$fill_params = $this->fill_params_provider->getFillParams();
if (!$fill_params) {  // ← getFillParams() возвращает FillParams, никогда не null
    return;
}
```

## Рекомендация

Удалить проверку. Если нужна защита «нет данных», проверять `$fill_params->isActive()` или `$fill_params->hasDataForSection('auth')`.
