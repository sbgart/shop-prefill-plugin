# Issue 39 — `ViewProvider::createCssVariablesString` — мёртвый дубликат

**Статус:** ✅ Закрыта  
**Приоритет:** 🟢 Низкий  
**Сложность фикса:** ⚡ Минутный  
**Файлы:**
- `lib/classes/view/shopPrefillPluginViewProvider.class.php`
- `lib/classes/view/shopPrefillPluginAssetsManager.class.php`

## Что было

В `shopPrefillPluginViewProvider` оставались неиспользуемые методы:
- `createCssVariablesString()` (дубликат логики, которая уже живёт в `AssetsManager`)
- `getFormattedPrice()`, `getFormattedMessage()` (не использовались в коде/шаблонах)

## Что сделано

Удалены мёртвые методы из `shopPrefillPluginViewProvider`. Генерация CSS-переменных остаётся в `shopPrefillPluginAssetsManager::createCssVariablesString()`.

