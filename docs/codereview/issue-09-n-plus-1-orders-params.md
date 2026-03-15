# Issue 09 — N+1 запросов при сборе коллекции доставок

**Статус:** ⬜ Открыта  
**Приоритет:** 🟠 Средний  
**Сложность фикса:** 🔧 Небольшой  
**Файл:** `fillparams/shopPrefillPluginFillParamsProvider.class.php`, строки 192–197

## Проблема

`getOrderParams()` делает отдельный SQL-запрос для каждого заказа. При 20 заказах в истории — 20 запросов.

```php
foreach ($orders_ids as $order_id) {
    $params = $this->getOrderProvider()->getOrderParams($order_id); // ← N запросов к БД
    if ($params) {
        $orders_params[$order_id] = $params;
    }
}
```

## Рекомендация

Использовать уже существующий метод `getUserOrdersParams()` из `OrderProvider`:

```php
// Один запрос для всех заказов сразу
$orders_params = $this->getOrderProvider()->getUserOrdersParams($contact_id);
```

> ⚠️ Аналогичный метод есть для авторизованных, но для гостей (`getAllOrderIdsByGuestHash`) тоже нужен батчевый вариант.
