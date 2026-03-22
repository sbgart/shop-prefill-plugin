# Issue 21 — `storeShippingTypeId`: `&&` вместо `||` — невалидный `order_id` не перехватывается

**Статус:** ⬜ Открыта
**Приоритет:** 🟠 Высокий  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `orders/shopPrefillPluginOrderProvider.class.php`, строки 68–70

## Проблема

Условие использует `&&` (AND), но должно `||` (OR):

```php
public function storeShippingTypeId(int $order_id, string $shipping_type_id): bool
{
    if (empty($shipping_type_id) && $order_id <= 0) {  // ← AND
        return false;
    }
    return $this->order_params_model->setOne($order_id, 'shipping_type_id', $shipping_type_id);
}
```

**Случай:** `$order_id = 0`, `$shipping_type_id = 'courier'`.  
Условие: `empty('courier') = false` AND `0 <= 0 = true` → `false AND true = false`.  
Ранний возврат НЕ срабатывает — вызывается `setOne(0, ...)` с невалидным ID.

## Рекомендация

```php
if (empty($shipping_type_id) || $order_id <= 0) {
    return false;
}
```
