# Issue 10 — Двойной вызов `getFillParams()` в `handleFrontendHead`

**Статус:** ⬜ Открыта  
**Приоритет:** 🟠 Средний  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `hooks/shopPrefillPluginFrontendHooks.class.php`, строки 67–82

## Проблема

`getFillParams()` не кэшируется внутри `FillParamsProvider` — каждый вызов делает запросы к БД (поиск последнего заказа, загрузка параметров).

```php
$fill_params = $this->fill_params_provider->getFillParams(); // ← 1-й вызов

// ...
if ($this->storefront_settings['prefill']['on_entry']) {
    $this->session_storage->preFillCheckoutParams(
        $this->fill_params_provider->getFillParams() // ← 2-й вызов (дублирует запросы к БД!)
    );
}
```

## Рекомендация

Повторно использовать уже полученный `$fill_params`:

```php
if ($this->storefront_settings['prefill']['on_entry']) {
    $this->session_storage->preFillCheckoutParams($fill_params);
}
```
