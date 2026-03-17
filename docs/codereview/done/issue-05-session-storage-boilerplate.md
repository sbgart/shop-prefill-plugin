# Issue 05 — `SessionStorageProvider` — дублирование шаблона «if null → из snapshot, иначе fill_params»

**Статус:** ✅ Закрыта  
**Приоритет:** 🟡 Низкий  
**Сложность фикса:** 🔨 Рефакторинг  
**Файл:** `sessionstorage/shopPrefillPluginSessionStorageProvider.class.php`

## Проблема

Все 6 методов `prepare*SectionParams` имели мёртвый guard `if ($fill_params === null) return;` — параметр никогда не был null, так как все вызовы передают не-nullable значение.

## Решение

- Убраны 6 мёртвых `if ($fill_params === null) return;` проверок
- Сигнатуры изменены с `?shopPrefillPluginFillParams` на `shopPrefillPluginFillParams`

Предложенный рефакторинг с `callable`-обёрткой признан избыточным: `prepareAuthSectionParams` — особый случай с дополнительной проверкой `isUserAuthenticated()`, callable добавляет индирекцию без реальной выгоды.
