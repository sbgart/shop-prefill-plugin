# Issue 11 — Мёртвый код `getSortedShippingMethods` в `PluginsProvider`

**Статус:** ✅ Закрыта  
**Приоритет:** 🟠 Средний  
**Файл:** `lib/classes/plugins/shopPrefillPluginPluginsProvider.class.php`

## Проблема

Метод `getSortedShippingMethods` не вызывался нигде в кодовой базе (YAGNI). Помимо этого, содержал dangling reference — после `foreach ($shippings as $id => &$shipping)` переменная `$shipping` оставалась живой ссылкой на последний элемент массива.

Статический кэш (`self::$shipping_methods_cache`) мутации не подвергался — PHP 7.4 корректно делает COW-разделение при взятии reference на элемент shared-массива.

## Решение

Метод `getSortedShippingMethods` удалён. Если сортировка потребуется — добавить без `&` через прямую запись по ключу (`$shippings[$id]['sort'] = ...`).
