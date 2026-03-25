# Issue 47 — Лишний `header('Content-Type: application/json')` в `waJsonController`

**Статус:** ⬜ Открыта  
**Приоритет:** 🟢 Низкий  
**Сложность фикса:** ⚡ Минутный  
**Файлы:**
- `actions/frontend/shopPrefillPluginFrontendClearStorage.controller.php`
- `actions/frontend/shopPrefillPluginFrontendResetAndRefill.controller.php`
- `actions/frontend/shopPrefillPluginFrontendResetFirstPrefillDone.controller.php`
- `actions/frontend/shopPrefillPluginFrontendToggleZen.controller.php`

## Проблема

Четыре контроллера содержат избыточную установку заголовка:

```php
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}
```

`waJsonController` **уже устанавливает** Content-Type в `afterExecute()`. Ручная установка:

1. Бессмысленна — перетирается фреймворком
2. Некорректна — вызывается до `$this->response`, т.е. при ошибке заголовок не будет соответствовать

Остальные контроллеры (`ApplyDelivery`, `Consent`, `ResetSnapshot`, `ForcePrefill`) этого не делают и работают правильно.

## Рекомендация

Удалить блок `if (!headers_sent()) { header(...) }` из всех четырёх контроллеров.
