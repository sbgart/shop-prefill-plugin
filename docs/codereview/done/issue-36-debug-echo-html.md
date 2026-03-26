# Issue 36 — Debug: `echo` HTML/JS напрямую в output buffer

**Статус:** ✅ Закрыта  
**Приоритет:** 🟡 Средний  
**Сложность фикса:** 🔧 Небольшой  
**Файл:** `debug/shopPrefillPluginDebug.class.php`, методы `renderDebugStack`, `scheduleDebugStackRender`

## Решение

- **Убран прямой вывод**: методы теперь возвращают строку (без `echo`).
- **Вставка через хук**: дебаг-HTML возвращается из `frontend_head` (return из `shopPrefillPlugin::frontendHead()`).
- **Безопасная передача в JS**: вместо ручного экранирования используется `json_encode()`.

