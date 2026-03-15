# Issue 02 — Баг: `storeComment` пишет не туда

**Статус:** ✅ Исправлено  
**Приоритет:** 🔴 Высокий  
**Затронутые файлы:**
- `orders/shopPrefillPluginOrderProvider.class.php`
- `hooks/shopPrefillPluginOrderHooks.class.php`
- `fillparams/shopPrefillPluginFillParamsProvider.class.php`

## Проблема

Комментарий сохранялся повторно в `shop_order_params`, тогда как Shop-Script **уже сохраняет** `comment` в основную таблицу `shop_order` при создании заказа (в `shopFrontendCheckout.action.php`):

```php
// shopFrontendCheckout.action.php — движок сам делает это
if (isset($checkout_data['comment'])) {
    $order['comment'] = $checkout_data['comment'];
}
```

Наш `storeComment` был мёртвым кодом, который дублировал уже готовое поведение.

## Исправление

**Удалено:**
- `storeComment()` из `shopPrefillPluginOrderProvider`
- `saveComment()` из `shopPrefillPluginOrderHooks`
- Вызов `$this->saveComment(...)` в `handleOrderActionCreate`

**Оставлено и корректно работает:**
- `getOrderComment()` — читает `comment` из `shop_order` по PK
- В `getFillParamsByOrderParams` — читает через `getOrderComment()` (в shop_order всегда актуальные данные, включая правки из бэкенда)
