# Issue 37 — `StorefrontCollection`: свойство `$storefronts` не инициализировано

**Статус:** ✅ Закрыта  
**Приоритет:** 🟡 Средний  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `storefronts/shopPrefillPluginStorefrontCollection.class.php`

## Проблема

Свойство было объявлено без значения по умолчанию:

```php
class shopPrefillPluginStorefrontCollection
{
    private array $storefronts;
    // ...
}
```

Из-за typed property код мог падать при первом же обращении (`has()`/`add()`/`getList()`):

```
Typed property shopPrefillPluginStorefrontCollection::$storefronts
must not be accessed before initialization
```

## Решение

Инициализировано при объявлении:

```php
private array $storefronts = [];
```
