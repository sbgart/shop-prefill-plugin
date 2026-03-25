# Issue 33 — `toArray()` утечка приватных свойств через `get_object_vars`

**Статус:** ⬜ Открыта  
**Приоритет:** 🟡 Средний  
**Сложность фикса:** 🔧 Небольшой  
**Файл:** `fillparams/shopPrefillPluginFillParams.class.php`, метод `toArray`

## Проблема

Метод `toArray()` использует `get_object_vars($this)`, который внутри класса возвращает **все** свойства, включая private:

```php
public function toArray(): array
{
    return get_object_vars($this);
}
```

В результате в массив попадают служебные свойства:

- `region_params` → `['country', 'region', 'city', 'zip', 'street']`
- `auth_params` → `['customer_type', 'auth_data']`
- `contact_params` → `['title', 'firstname', ...]`
- `payment_params` → `['payment_id', ...]`
- `shipping_params` → `['shipping_id', ...]`
- `active` → `false`

Эти массива-маппингов:
1. Утекают на фронтенд через ParamsChoice action (JSON-ответ)
2. Утекают в debug-панель (fill_params_data)
3. Ломают `isSameDeliveryOption` при попытке обращения к `$this->$property`, если `$property` — массив-маппинг

## Рекомендация

Явно перечислить свойства данных:

```php
public function toArray(): array
{
    return [
        'id' => $this->id,
        'country' => $this->country,
        'country_name' => $this->country_name,
        'region' => $this->region,
        // ... остальные data-свойства
    ];
}
```

Или отфильтровать:

```php
public function toArray(): array
{
    $vars = get_object_vars($this);
    unset($vars['region_params'], $vars['auth_params'], $vars['contact_params'],
          $vars['payment_params'], $vars['shipping_params']);
    return $vars;
}
```
