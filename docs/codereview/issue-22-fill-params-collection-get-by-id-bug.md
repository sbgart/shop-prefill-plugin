# Issue 22 — `FillParamsCollection::getById()` всегда возвращает `null`

**Статус:** ⬜ Открыта
**Приоритет:** 🟠 Высокий  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `fillparams/shopPrefillPluginFillParamsCollection.class.php`

## Проблема

Элементы добавляются с автоинкрементным индексом (`0, 1, 2...`), но `getById` ищет по этому индексу, а не по `fill_params->getId()`:

```php
public function add(shopPrefillPluginFillParams $params): void
{
    $this->collection[] = $params; // индексы: 0, 1, 2, 3...
}

public function getById(int $id = 0): ?shopPrefillPluginFillParams
{
    if (isset($this->collection[$id])) { // ищет по индексу, не по order_id!
        return $this->collection[$id];
    }
    return null;
}
```

Элемент с `fill_params->getId() = 42` хранится по индексу `[0]`. `getById(42)` вернёт `null`. Метод работает корректно только если `$id` совпадает с порядковым номером добавления.

## Рекомендация

```php
public function getById(int $id): ?shopPrefillPluginFillParams
{
    foreach ($this->collection as $item) {
        if ($item->getId() === $id) {
            return $item;
        }
    }
    return null;
}
```

Или хранить с ключом по ID при добавлении: `$this->collection[$params->getId()] = $params`.
