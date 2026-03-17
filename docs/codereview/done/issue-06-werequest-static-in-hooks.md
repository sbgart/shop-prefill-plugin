# Issue 06 — `OrderProvider` — неинкапсулированный доступ к `waRequest` в хуке

**Статус:** ✅ Закрыта  
**Приоритет:** 🟡 Низкий  
**Сложность фикса:** 🔧 Небольшой  
**Файл:** `hooks/shopPrefillPluginOrderHooks.class.php`, строки 82–85

## Проблема

Статичный вызов `waRequest::post()` в классе `OrderHooks` нарушает принципы DI — `OrderHooks` знает о глобальном состоянии. При тестировании невозможно подменить запрос.

```php
// Если в сессии нет — читаем прямо из POST
$shipping_post = waRequest::post('shipping', [], waRequest::TYPE_ARRAY_TRIM);
```

## Рекомендация

Инжектировать `waRequest` в `OrderHooks` через конструктор (аналогично `CheckoutHooks`).

## Выполнено

- В конструктор `OrderHooks` добавлен параметр `waRequest $request`, в `saveShippingType()` используется `$this->request->post()` вместо `waRequest::post()`.
- В `shopPrefill.plugin.php` в `getOrderHooks()` передаётся `wa()->getRequest()`.
