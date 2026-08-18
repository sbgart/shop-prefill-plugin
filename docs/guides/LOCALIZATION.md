# Локализация плагина Prefill

**Дата создания:** 2026-01-03  
**Версия:** 1.0

## Обзор

Плагин поддерживает многоязычность через систему локализации Webasyst. В настоящее время поддерживаются:

- 🇷🇺 Русский язык (`ru_RU`)
- 🇬🇧 Английский язык (`en_US`)

## Осознанные исключения из локализации

Не весь текст плагина обязан идти через `.po`. Debug-инструментарий разработчика — исключение:

- `js/prefill.debug.js` — плавающая debug-панель на витрине
- `shopPrefillPluginDebug::renderErrorsDebugHtml()` (`lib/classes/debug/shopPrefillPluginDebug.class.php`) и весь класс `shopPrefillPluginDebug`

Эти строки хардкожены на русском и **не переводятся сознательно**: панель видна только при одновременно включённом глобальном debug-режиме Webasyst (`waSystemConfig::isDebug()`) и настройке плагина `prefill.debug_panel` — покупатель её никогда не видит. Локализовывать код, который не доходит до покупателя, не нужно (см. `docs/codereview/done/issue-69-hardcoded-russian-strings-frontend.md`).

Все остальные customer-facing строки — в JS-модулях (`js/modules/*.js`, `js/prefill.frontend.js`) и в PHP-конфигах дефолтов (`lib/config/*.settings.php`) — обязаны идти через `_wp()` / ключи `.po`, без исключений.

## Структура файлов локализации

```
wa-apps/shop/plugins/prefill/
└── locale/
    ├── en_US/
    │   └── LC_MESSAGES/
    │       ├── shop_prefill.po    # Исходный файл переводов (редактируемый)
    │       └── shop_prefill.mo    # Скомпилированный файл (генерируется автоматически)
    └── ru_RU/
        └── LC_MESSAGES/
            ├── shop_prefill.po    # Исходный файл переводов (редактируемый)
            └── shop_prefill.mo    # Скомпилированный файл (генерируется автоматически)
```

## Файлы переводов (.po)

### Формат

Файлы `.po` используют стандартный формат GNU gettext:

```po
msgid "ключ.перевода"
msgstr "Переведенный текст"
```

### Пример из плагина

```po
msgid "Error"
msgstr "Ошибка"

msgid "error.file-not-uploaded"
msgstr "Пожалуйста, выберите файл с настройками для импорта."

msgid "setting.plugin_active"
msgstr "Статус плагина"
```

### Рекомендации по ключам

1. **Используйте точечную нотацию** для группировки:

   - `error.*` - ошибки
   - `setting.*` - настройки
   - `title.*` - всплывающие подсказки
   - `tab.*` - вкладки
   - `hint.*` - подсказки
   - `variable.*` - переменные для шаблонов

2. **Ключи должны быть уникальными** в рамках плагина

3. **Используйте понятные ключи** вместо порядковых номеров

## Компиляция переводов

### Команда компиляции

После редактирования `.po` файлов необходимо их скомпилировать в `.mo` формат:

```bash
cd /Users/user/Project/wa-dev
php wa.php locale shop/plugins/prefill
```

### Вывод успешной компиляции

```
Result: SUCCESS. Words for en_US locale:
Total: 231
Updated: 231
New: 0

Result: SUCCESS. Words for ru_RU locale:
Total: 231
Updated: 231
New: 0
```

### Когда нужна компиляция

✅ После добавления новых строк в `.po` файлы  
✅ После изменения существующих переводов  
✅ После обновления плагина из репозитория

## Очистка кэша локализации

После компиляции переводов может потребоваться очистить кэш:

```bash
cd /Users/user/Project/wa-dev
rm -f wa-cache/*/apps/shop_minorder/locale/*.php
```

Или удалить весь кэш плагина:

```bash
find wa-cache -name "*.php" -path "*/shop_minorder/*" -delete
```

## Использование локализации в коде

### 1. В PHP коде (Action, Controller)

#### Загрузка локализации

В начале `execute()` метода:

```php
class shopMinorderPluginSettingsAction extends waViewAction
{
    public function execute()
    {
        // Загрузка домена локализации
        waLocale::loadByDomain(array('shop', 'prefill'));
        waSystem::pushActivePlugin('prefill', 'shop');

        // ... остальной код
    }
}
```

#### Использование в коде

```php
// Простой перевод
$error = _wp('error.file-not-uploaded');

// С параметрами (sprintf)
$message = sprintf(_wp('product.count'), $count);
```

### 2. В Smarty шаблонах

#### Использование модификатора |\_wp

```smarty
{* Простой перевод *}
<h1>{'setting.plugin_active'|_wp}</h1>

{* Или краткая форма *}
<h1>[`setting.plugin_active`]</h1>

{* С экранированием для JavaScript *}
<script>
  var message = "{'error.file-not-uploaded'|_wp|escape:'javascript'}";
</script>
```

### 3. В JavaScript коде

JavaScript не имеет прямого доступа к системе локализации PHP. Переводы нужно передавать явно.

#### Шаг 1: Передача переводов в JavaScript

В шаблоне (например, `templates/actions/settings/blocks/Head.html`):

```smarty
{* Локализация для JavaScript *}
<script>
  var $_locale = {
    'Error': "{'Error'|_wp|escape:'javascript'}",
    'error.unexpected': "{'error.unexpected'|_wp|escape:'javascript'}",
    'error.file-not-uploaded': "{'error.file-not-uploaded'|_wp|escape:'javascript'}",
    'Import': "{'Import'|_wp|escape:'javascript'}",
    'cancel': "{'cancel'|_wp|escape:'javascript'}",
    'Save': "{'Save'|_wp|escape:'javascript'}"
  };

  // Глобальная функция локализации для JS
  window.$_ = function(key) {
    return $_locale[key] || key;
  };
</script>
```

**Важно:** Этот скрипт должен быть подключен **ДО** основных JS файлов плагина.

#### Шаг 2: Использование в JavaScript

```javascript
// Простое использование
var errorTitle = $_("Error");
var errorMessage = $_("error.file-not-uploaded");

// С проверкой существования функции (для совместимости)
var message = typeof $_ !== "undefined" ? $_("error.file-not-uploaded") : "Please select a settings file to import.";

// В функции
MinorderSettings.showErrorDialog = function (errors) {
  var errorTitle = typeof $_ !== "undefined" ? $_("Error") : "Error";
  // ...
};
```

## Добавление нового перевода

### Пример: Добавление сообщения об успешном сохранении

#### 1. Добавить в `.po` файлы

**`locale/ru_RU/LC_MESSAGES/shop_prefill.po`:**

```po
msgid "success.saved"
msgstr "Настройки успешно сохранены"
```

**`locale/en_US/LC_MESSAGES/shop_prefill.po`:**

```po
msgid "success.saved"
msgstr "Settings saved successfully"
```

#### 2. Скомпилировать

```bash
cd /Users/user/Project/wa-dev
php wa.php locale shop/plugins/prefill
```

#### 3. Очистить кэш

```bash
rm -f wa-cache/*/apps/shop_minorder/locale/*.php
```

#### 4. Использовать в коде

**PHP:**

```php
$this->response['message'] = _wp('success.saved');
```

**Smarty:**

```smarty
<div class="success">[`success.saved`]</div>
```

**JavaScript (если нужно):**

Сначала добавить в шаблон `Head.html`:

```smarty
'success.saved': "{'success.saved'|_wp|escape:'javascript'}",
```

Затем использовать:

```javascript
alert($_("success.saved"));
```

## Рабочий процесс при разработке

### Последовательность действий:

1. **Редактировать `.po` файлы**

   - Открыть нужный файл в текстовом редакторе или Poedit
   - Добавить/изменить переводы

2. **Скомпилировать**

   ```bash
   cd /Users/user/Project/wa-dev && php wa.php locale shop/plugins/prefill
   ```

3. **Очистить кэш** (если изменения не видны)

   ```bash
   rm -f wa-cache/*/apps/shop_minorder/locale/*.php
   ```

4. **Обновить страницу** в браузере (Ctrl+Shift+R для полной очистки кэша)

## Часто встречающиеся проблемы

### Проблема: Отображается ключ вместо перевода

**Причины:**

- Не скомпилированы `.po` файлы в `.mo`
- Не очищен кэш локализации
- Не вызван `waLocale::loadByDomain()` в action
- Не вызван `waSystem::pushActivePlugin()` в action

**Решение:**

```bash
# 1. Скомпилировать
cd /Users/user/Project/wa-dev && php wa.php locale shop/plugins/prefill

# 2. Очистить кэш
rm -f wa-cache/*/apps/shop_minorder/locale/*.php

# 3. Проверить код action
waLocale::loadByDomain(array('shop', 'prefill'));
waSystem::pushActivePlugin('prefill', 'shop');
```

### Проблема: В JavaScript отображается ключ локализации

**Причина:** Переводы не переданы в JavaScript контекст

**Решение:**

1. Проверить, что в шаблоне есть определение `$_locale` и функции `$_()`
2. Проверить порядок подключения скриптов (локализация должна быть первой)
3. Проверить консоль браузера: `console.log($_("Error"))`

### Проблема: Перевод работает в PHP, но не в JS

**Причина:** Ключ не добавлен в объект `$_locale` в шаблоне

**Решение:**
Добавить ключ в `templates/actions/settings/blocks/Head.html`:

```smarty
'новый.ключ': "{'новый.ключ'|_wp|escape:'javascript'}",
```

## Структура ключей локализации в плагине

### Основные группы

| Группа       | Назначение                 | Примеры                                       |
| ------------ | -------------------------- | --------------------------------------------- |
| `error.*`    | Сообщения об ошибках       | `error.unexpected`, `error.file-not-uploaded` |
| `success.*`  | Сообщения об успехе        | `success.saved`                               |
| `warning.*`  | Предупреждения             | `warning.storefront_disabled`                 |
| `setting.*`  | Названия настроек          | `setting.plugin_active`, `setting.min_amount` |
| `title.*`    | Всплывающие подсказки      | `title.plugin_active`, `title.import`         |
| `hint.*`     | Развернутые подсказки      | `hint.main`, `hint.css_selector`              |
| `tab.*`      | Названия вкладок           | `tab.general`, `tab.products`                 |
| `header.*`   | Заголовки страниц          | `header.category`, `header.product`           |
| `variable.*` | Переменные для шаблонов    | `variable.minimum_amount`                     |
| `da.*`       | Dynamic Appearance (стили) | `da.background`, `da.text_align`              |
| `action.*`   | Действия/кнопки            | `action.set_minimum`, `action.export`         |
| `menu.*`     | Пункты меню                | `menu.help`, `menu.feedback`                  |

### Общие ключи

Некоторые ключи используются без префикса для общих элементов:

- `Error`, `Import`, `Export`, `Save`, `Close`, `cancel`
- `On`, `Off`, `Yes`, `No`

## Инструменты для работы с переводами

### Poedit

Рекомендуемый редактор для `.po` файлов:

- **Сайт:** https://poedit.net/
- **Особенности:**
  - Удобный GUI
  - Подсветка синтаксиса
  - Проверка переводов
  - Автоматическое обновление статистики

### Ручное редактирование

`.po` файлы можно редактировать в любом текстовом редакторе:

- VS Code с расширением "gettext"
- Sublime Text
- PhpStorm

## Best Practices

1. ✅ **Всегда компилируйте после изменений** `.po` файлов
2. ✅ **Используйте понятные ключи** вместо "error1", "error2"
3. ✅ **Группируйте ключи по смыслу** через точечную нотацию
4. ✅ **Добавляйте переводы сразу для всех языков**
5. ✅ **Очищайте кэш** если изменения не видны
6. ✅ **Используйте экранирование** при передаче в JavaScript: `|escape:'javascript'`
7. ✅ **Документируйте новые ключи** если они используются в разных местах
8. ⛔ **Не хардкодьте тексты** на конкретном языке в коде
9. ⛔ **Не забывайте** вызывать `waLocale::loadByDomain()` в action классах
10. ⛔ **Не используйте одинаковые ключи** для разных текстов

## Пример полного цикла добавления локализованной функции

### Задача: Добавить кнопку "Очистить все" с подтверждением

#### 1. Добавить ключи в `.po` файлы

**`locale/ru_RU/LC_MESSAGES/shop_prefill.po`:**

```po
msgid "action.clear_all"
msgstr "Очистить все"

msgid "confirm.clear_all"
msgstr "Вы уверены, что хотите удалить все настройки? Это действие нельзя отменить."

msgid "success.cleared"
msgstr "Все настройки успешно удалены"
```

**`locale/en_US/LC_MESSAGES/shop_prefill.po`:**

```po
msgid "action.clear_all"
msgstr "Clear all"

msgid "confirm.clear_all"
msgstr "Are you sure you want to delete all settings? This action cannot be undone."

msgid "success.cleared"
msgstr "All settings successfully deleted"
```

#### 2. Скомпилировать

```bash
cd /Users/user/Project/wa-dev && php wa.php locale shop/plugins/prefill
```

#### 3. Добавить в JavaScript (если нужно)

В `templates/actions/settings/blocks/Head.html`:

```smarty
'action.clear_all': "{'action.clear_all'|_wp|escape:'javascript'}",
'confirm.clear_all': "{'confirm.clear_all'|_wp|escape:'javascript'}",
'success.cleared': "{'success.cleared'|_wp|escape:'javascript'}",
```

#### 4. Использовать в коде

**Шаблон:**

```smarty
<button type="button" class="js-clear-all">
  [<code>action.clear_all</code>]
</button>
```

**JavaScript:**

```javascript
$(".js-clear-all").on("click", function () {
  if (confirm($_("confirm.clear_all"))) {
    // Выполнить очистку
    alert($_("success.cleared"));
  }
});
```

## Заключение

Правильная локализация плагина обеспечивает:

- ✅ Удобство использования для международной аудитории
- ✅ Профессиональный вид интерфейса
- ✅ Легкость поддержки и расширения
- ✅ Соответствие стандартам Webasyst

**Важно помнить:**

- Компиляция обязательна после каждого изменения `.po` файлов
- JavaScript требует явной передачи переводов через шаблон
- Кэш может препятствовать отображению изменений
