# Issue 36 — Debug: `echo` HTML/JS напрямую в output buffer

**Статус:** ⬜ Открыта  
**Приоритет:** 🟡 Средний  
**Сложность фикса:** 🔧 Небольшой  
**Файл:** `debug/shopPrefillPluginDebug.class.php`, методы `renderDebugStack`, `scheduleDebugStackRender`

## Проблема

Методы используют `echo` для вывода HTML и JavaScript прямо в output buffer:

```php
// scheduleDebugStackRender():
echo "<script>...</script>";

// renderDebugStack():
echo "<script src=\"{$js_url}\"></script>";
echo "<script>...</script>";
```

Это нарушает архитектуру Webasyst, где вывод должен идти через `waView` или return из хука. Проблемы:

1. **Непредсказуемый порядок вывода** — `echo` может попасть до `<!DOCTYPE>` или после `</html>`
2. **Невозможно кэшировать** — `echo` обходит буферизацию
3. **XSS-риск** — `$debug_html_escaped` экранируется вручную через `str_replace` вместо `json_encode`, что может пропустить edge-cases
4. **Побочный эффект** — статический метод неявно меняет глобальный output

## Рекомендация

Возвращать HTML строкой и вставлять через стандартный механизм хуков:

```php
public static function renderDebugStack(): string
{
    // ...
    return $debug_html;
}
```

Вызывающий код (`FrontendHooks`) уже имеет механизм добавления HTML через addJs/addCss или return из хука.

Для передачи данных в JS лучше использовать `json_encode()` вместо ручного экранирования.
