# Issue 54 — Часть бэкенд-эндпоинтов без проверки прав

**Статус:** ✅ Решена
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

**Поправка 24.08.2026 (issue-74 §6):** утверждение выше не подтвердилось повторной проверкой — `enableSecurity()` в Webasyst нигде не вызывается (`grep -rn "enableSecurity" wa-system wa-apps/shop` не находит ни одного вызова вне самого класса Smarty), политики безопасности нет, `{$x|shell_exec}` исполняется как есть. RCE здесь **есть**, но это не открывает новую дыру: единственная защита — тот самый `isAdmin('shop')`, который эта задача и добавила, — и её достаточно, потому что уровень доступа совпадает со штатным редактором тем Webasyst. Вывод задачи («вынести проверку прав в базовый класс») не менялся, но обоснование «RCE здесь нет» ниже по тексту нужно читать с этой поправкой. Актуальный разбор риска — [RULES.md B4](../../concept/RULES.md#границы) и [ADMIN-SETTINGS.md](../../concept/ADMIN-SETTINGS.md#zen-mode-общий-и-пер-инстансный-шаблон-сводки).

Остаётся утечка настроек и несогласованность модели доступа внутри одного плагина.

## Рекомендация

Вынести проверку прав в общий базовый класс (`shopPrefillPluginSettingsBaseController` / `...BaseAction`) и применить ко всем settings-экшенам. Заодно проверять, что `code` витрины из POST существует (см. [issue-57](issue-57-minor-robustness-findings.md)).

## Решение

Добавлены два базовых класса в `lib/classes/settings/`:

- `shopPrefillPluginSettingsBaseController` (extends `waJsonController`) — для AJAX/JSON-эндпоинтов;
- `shopPrefillPluginSettingsBaseAction` (extends `waViewAction`) — для view-экшенов, рендерящих HTML/шаблоны.

Оба финализируют `execute()`: проверяют `wa()->getUser()->isAdmin(shopPrefillPlugin::APP_ID)` и делегируют в абстрактный `handle()`, который переопределяют конкретные экшены (переименовано из `execute()`). Контроллер отвечает `{"status":"fail","errors":"Forbidden"}`, экшен — бросает `waRightsException` (стандартная страница 403 Webasyst).

Все 8 settings-экшенов плагина переведены на эти базовые классы — включая три, где проверка уже была (`ReadLogs`, `ClearLog`, `SaveLogLevel`): дублирующийся код `if (!wa()->getUser()->isAdmin('shop')) {...}` убран, теперь проверка в одном месте.

Изменённые файлы:
- `lib/classes/settings/shopPrefillPluginSettingsBaseController.class.php` (новый)
- `lib/classes/settings/shopPrefillPluginSettingsBaseAction.class.php` (новый)
- `lib/actions/shopPrefillPluginSettingsGetCss.controller.php`
- `lib/actions/shopPrefillPluginSettingsTemplatePreview.controller.php`
- `lib/actions/shopPrefillPluginSettingsStorefront.action.php`
- `lib/actions/shopPrefillPluginSettingsTemplateEditor.action.php`
- `lib/actions/shopPrefillPluginSettings.action.php`
- `lib/actions/shopPrefillPluginSettingsReadLogs.controller.php`
- `lib/actions/shopPrefillPluginSettingsClearLog.controller.php`
- `lib/actions/shopPrefillPluginSettingsSaveLogLevel.controller.php`

**Требуется проверка в браузере:** сама правка чисто серверная (`php -l` пройден на всех файлах), но её эффект — HTTP-доступ — тестами не покрыт. Нужно вручную:
1. Под админом (`admin`/`123`) убедиться, что страница настроек плагина и все её AJAX-действия (CSS-редактор, редактор шаблонов, превью шаблона, переключение витрины, логи) по-прежнему работают.
2. Под сотрудником без `isAdmin('shop')` (или гостем/разлогиненным через `?action=logout`) убедиться, что прямой запрос к `?module=prefillPluginSettings...` теперь отдаёт `403`/`Forbidden`, а не данные.
