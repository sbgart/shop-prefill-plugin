# Issue 11 — Статические кэши `PluginsProvider` мутируются при сортировке

**Статус:** ⬜ Открыта  
**Приоритет:** 🟠 Средний  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `plugins/shopPrefillPluginPluginsProvider.class.php`

## Проблема

В рамках одного PHP-запроса кэш нормален. Но метод `getSortedShippingMethods` **мутирует элементы кэша** через `&$shipping` (строка 35), при следующем вызове `getShippingMethods()` вернётся уже изменённый массив.

```php
private static ?array $shipping_methods_cache = null;
private static ?array $payment_methods_cache = null;
```

## Рекомендация

Убрать `&` (pass by reference) в `getSortedShippingMethods` или работать с копией массива.
