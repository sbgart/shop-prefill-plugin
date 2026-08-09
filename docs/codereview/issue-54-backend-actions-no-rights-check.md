# Issue 54 — Часть бэкенд-эндпоинтов без проверки прав

**Статус:** ⬜ Открыта
**Приоритет:** 🟡 Средний
**Сложность фикса:** 🔧 Тривиальный
**Файлы:**
- `lib/actions/shopPrefillPluginSettingsGetCss.controller.php`
- `lib/actions/shopPrefillPluginSettingsStorefront.action.php`
- `lib/actions/shopPrefillPluginSettingsTemplateEditor.action.php`
- `lib/actions/shopPrefillPluginSettingsTemplatePreview.controller.php`
- `lib/actions/shopPrefillPluginSettings.action.php`

## Проблема

`SettingsReadLogs`, `SettingsClearLog`, `SettingsSaveLogLevel` начинаются с проверки:

```php
if (!wa()->getUser()->isAdmin('shop')) {
    $this->errors = 'Forbidden';
    return;
}
```

Остальные бэкенд-экшены плагина такой проверки не делают. Их может дёрнуть **любой сотрудник с доступом к приложению shop** (например, менеджер заказов с урезанными правами):

- `GetCss` — прочитать сохранённый CSS любой витрины по её коду;
- `SettingsStorefront` / `Settings` — получить все настройки плагина по витринам;
- `TemplateEditor` / `TemplatePreview` — открыть редактор и отрендерить произвольный Smarty-шаблон.

**RCE здесь нет** — проверено: в Webasyst включена политика безопасности Smarty, произвольные PHP-функции и чтение файлов из строковых шаблонов заблокированы:

```
{'echo PWNED'|shell_exec}  → PHP function 'shell_exec' not allowed by security setting
{include file='.../db.php'} → file 'db.php' not allowed by security setting
```

Остаётся утечка настроек и несогласованность модели доступа внутри одного плагина.

## Рекомендация

Вынести проверку прав в общий базовый класс (`shopPrefillPluginSettingsBaseController` / `...BaseAction`) и применить ко всем settings-экшенам. Заодно проверять, что `code` витрины из POST существует (см. [issue-57](issue-57-minor-robustness-findings.md)).
