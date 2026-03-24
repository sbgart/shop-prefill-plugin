# Issue 20 — `SELECT *` вместо `SELECT id` в `getLastOrderIdByContactId`

**Статус:** ✅ Закрыта  
**Приоритет:** 🟡 Средний  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `orders/shopPrefillPluginOrderProvider.class.php`, строка 32

## Проблема

Метод запрашивает все поля таблицы `shop_order`, хотя использует только `id`:

```php
$last_order_id = $this->order_model->select("*")  // ← все поля
    ->where('contact_id=?', $contact_id)
    ->order('id DESC')->fetchField();
```

`shop_order` — широкая таблица (20+ полей включая `params` и `comment`). `SELECT *` передаёт лишние данные по сети и увеличивает нагрузку на БД.

## Решение

Заменён запрос на выбор только нужного поля:

```php
$last_order_id = $this->order_model->select("id")
    ->where('contact_id=?', $contact_id)
    ->order('id DESC')->fetchField();
```
