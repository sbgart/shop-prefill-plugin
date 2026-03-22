# Issue 17 — N+1 запросов: `getOrderComment` и `getContactIdFromOrder` внутри цикла

**Статус:** ⬜ Открыта  
**Приоритет:** 🟠 Высокий  
**Сложность фикса:** 🔧 Небольшой  
**Файл:** `fillparams/shopPrefillPluginFillParamsProvider.class.php`, метод `getFillParamsByOrderParams`

## Проблема

В `getFillParamsCollection` для каждого уникального заказа вызывается `getFillParamsByOrderParams`, который делает **2 отдельных SQL-запроса**:

```php
// Запрос 1: SELECT comment FROM shop_order WHERE id = ?
$comment = $this->order_provider->getOrderComment($order_id);

// Запрос 2: SELECT contact_id FROM shop_order WHERE id = ?
$contact_id = $this->order_provider->getContactIdFromOrder($order_id);
// Затем: new waContact($contact_id) → ещё запрос к wa_contact
```

При 10 уникальных заказах в коллекции — **20+ лишних SQL-запросов** к таблице `shop_order`.  
Оба поля есть в одной таблице и могут быть получены одним запросом.

## Рекомендация

Добавить в `OrderProvider` батчевый метод:

```php
public function getOrdersDataByIds(array $ids): array
{
    // SELECT id, contact_id, comment FROM shop_order WHERE id IN (...)
    return $this->order_model->select('id, contact_id, comment')
        ->where('id IN (?)', [$ids])
        ->fetchAll('id');
}
```

Загружать данные одним запросом до цикла и передавать их в `getFillParamsByOrderParams`.
