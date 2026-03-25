# Issue 41 — `FillParamsCollection::toArray()` сортировка по несуществующему ключу `sort`

**Статус:** ✅ Решена  
**Приоритет:** 🟡 Средний  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `fillparams/shopPrefillPluginFillParamsCollection.class.php`, метод `toArray`

## Проблема

Метод поддерживает сортировку по ключу `sort`:

```php
public function toArray(bool $sort = false, ?int $limit = null): array
{
    // ...
    if ($sort) {
        uasort($result, function ($a, $b) {
            return strcmp($a["sort"], $b["sort"]);
        });
    }
    return $result;
}
```

**Но:** класс `shopPrefillPluginFillParams` **не имеет** свойства `sort`. Метод `toArray()` объекта FillParams никогда не вернёт ключ `sort`.

При вызове `toArray(true)` PHP выдаст:
```
Notice: Undefined index: sort
```

И `strcmp(null, null)` вернёт `0`, так что сортировка ничего не сделает.

## Рекомендация

Если сортировка реально не нужна — удалить параметр `$sort` и соответствующий код. Если нужна — добавить поле `sort` в `FillParams` или использовать существующее поле (например, `id` для сортировки по дате заказа).
