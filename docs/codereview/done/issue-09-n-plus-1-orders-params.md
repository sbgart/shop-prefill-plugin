# Issue 09 — N+1 запросов при сборе коллекции доставок

**Статус:** ✅ Закрыта  
**Приоритет:** 🟠 Средний  
**Файл:** `fillparams/shopPrefillPluginFillParamsProvider.class.php`

## Проблема

`getOrderParams()` делал отдельный SQL-запрос для каждого заказа. При 20 заказах в истории — 20 запросов.

```php
foreach ($orders_ids as $order_id) {
    $params = $this->getOrderProvider()->getOrderParams($order_id); // N запросов
    if ($params) {
        $orders_params[$order_id] = $params;
    }
}
```

## Решение

Добавлен метод `getOrdersParamsByIds(array $order_ids)` в `OrderProvider` — один батчевый запрос.
Метод принимает уже готовый массив IDs (который к этому моменту уже получен), без повторной выборки.
Работает одинаково для авторизованных и гостей.

```php
$orders_params = $this->getOrderProvider()->getOrdersParamsByIds($orders_ids);
```

Неиспользуемый метод `getUserOrdersParams()` удалён.
