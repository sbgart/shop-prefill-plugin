# Issue 34 — `FillCheckoutParamsController`: `return json_encode()` вместо `$this->response`

**Статус:** ⬜ Открыта  
**Приоритет:** 🔴 Критический  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `actions/frontend/shopPrefillPluginFrontendFillCheckoutParams.controller.php`

## Проблема

Контроллер наследует `waJsonController`, но вместо стандартного механизма ответа использует `return json_encode()`:

```php
class shopPrefillPluginFrontendFillCheckoutParamsController extends waJsonController
{
    public function execute()
    {
        // ...
        return json_encode(array('status' => 'success'));
        // ...
        return json_encode(array('status' => 'error', 'message' => $e->getMessage()));
    }
}
```

В `waJsonController` метод `execute()` не должен возвращать значение — `return` просто игнорируется. Контроллер **никогда** не отправляет ответ клиенту.

**Сравните** с исправленным `ApplyDeliveryController`, который использует `$this->response / $this->errors`.

## Рекомендация

```php
public function execute()
{
    // ...
    $this->response = ['status' => 'success'];
    // ...
    $this->errors = $e->getMessage();
}
```
