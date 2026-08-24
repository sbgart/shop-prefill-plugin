# Issue 74 — Мелкие находки второго прохода ревью

**Статус:** 🟡 Открыта (4 из 8 закрыты — сверено с кодом 24.08.2026)
**Приоритет:** 🟢 Низкий
**Сложность фикса:** 🔧 Тривиальные, независимые друг от друга

Продолжение [issue-57](issue-57-minor-robustness-findings.md). Каждый пункт самостоятельный.

## 1. Общий `waView` загрязняется без восстановления — ✅ закрыто

`ZenMode::renderGroupSummary()` аккуратно сохраняла и возвращала переменные вида (`$old_vars` + `clearAssign`). Два соседних места писали в тот же singleton-view и ничего не восстанавливали:

- `ZenMode::renderCollapseBlock()` — `group`, `is_collapsed`, `icon_url`, `icon_is_default`, `icon_sprite_url`, `summary_html`, `zen_toggle_button_extra_classes`;
- `ViewProvider::render()` — `plugin_url` + всё, что передали (для чекбокса согласия — `has_consent`).

Проверено 24.08.2026 по коду: паттерн унифицирован именно так, как предлагалось — общий статический хелпер `shopPrefillPluginViewProvider::withScopedVars(waView $view, array $vars, callable $render)` (`lib/classes/view/shopPrefillPluginViewProvider.class.php`) сохраняет только реально перезаписанные ключи, подставляет `$vars`, выполняет `$render` и в `finally` восстанавливает старые значения / чистит новые. `renderCollapseBlock()` и `renderGroupSummary()` (`ZenMode.class.php`) и `ViewProvider::render()` теперь все три вызывают этот хелпер вместо прямого `$view->assign()`.

## 2. `waRequest::post('code')` без типа → `TypeError` вместо 400 — ✅ закрыто

`shopPrefillPluginSettingsStorefrontAction::handle()`:

```php
$storefront_code = waRequest::post('code');
…
$storefront = $plugin->getStorefrontProvider()->findStorefront($storefront_code);
```

`findStorefront(?string $storefront_code)` при массиве в `code` получал бы `TypeError` — а это `Error`, не `Exception`, ловилок нет → 500.

Проверено 24.08.2026 по коду: строка теперь `waRequest::post('code', '', waRequest::TYPE_STRING_TRIM)` (`lib/actions/shopPrefillPluginSettingsStorefront.action.php:12`). Оговорка про `FillCheckoutParams` снята сама собой — контроллер удалён целиком по [issue-62](issue-62-dead-unguarded-fill-checkout-endpoint.md).

## 3. Undefined index при заказе с регионом без страны — ✅ закрыто

`FillParamsProvider::getFillParamsByOrderParams()`:

```php
if (isset($order_params['shipping_address.region'])) {
    $fill_params->setRegion($order_params['shipping_address.region']);
    $region_name = $this->location_provider->getRegionName(
        $order_params['shipping_address.country'],   // ← без isset
        $order_params['shipping_address.region']
    );
```

Ключ `country` проверяется отдельно выше, здесь — нет. Заказ, где регион есть, а страна не сохранилась (импорт, API, старые данные), даёт notice в PHP 7.4 и warning в PHP 8.

Исправлено 24.08.2026: `$order_params['shipping_address.country'] ?? null` (`lib/classes/fillparams/shopPrefillPluginFillParamsProvider.class.php:453`). `getRegionName($country, $region)` у себя не типизирован, `null` проходит как и раньше проходил бы результат отсутствующего ключа — поведение то же, notice убран. Прогнан весь набор `tests/*Test.php` — без регрессий.

## 4. Просмотрщик логов перечитывает оба файла целиком на каждую страницу пагинации — ✅ закрыто

`SettingsReadLogs::handle()` → `LogReader::readMerged()` читает до 1 МБ с хвоста каждого файла (+ ротированные поколения), парсит регулярками **все** записи, сортирует `usort`'ом — и только потом делает `array_slice($all_reversed, $offset, $limit)`. При скролле на 10 страниц это 10 полных разборов ~2 МБ. Админский путь, некритично, но при активном дебаге заметно. Вариант: кэшировать распарсенный массив в сессии/файле по mtime логов.

Исправлено 24.08.2026: `readMerged()` кэширует результат файлом в приватном `wa-data/protected/shop/plugins/prefill/cache/logs-merged.cache` (`lib/classes/log/shopPrefillPluginLogReader.class.php`). Отпечаток — `mtime:size` всех 4 файлов (main + `.1`, error + `.1`); при совпадении с сохранённым в кэше парсинг и сортировка пропускаются целиком, при расхождении (новая запись в лог, ротация) — кэш перезаписывается. `unserialize()` вызывается с `allowed_classes => false` (файл пишет только сам плагин, но от инъекции объектов подстраховались).

Проверено в браузере: открыл «Отладка → Просмотр логов» (10205 записей уровня debug) — файл кэша появился на диске сразу, `stat` до/после повторного нажатия «Обновить» показал одинаковые mtime/size (кэш отработал, повторного парсинга не было). Затем `touch` на `prefill.plugin.log` (эмуляция новой записи) + «Обновить» — mtime кэш-файла изменился, список в UI отрисовался корректно (подтверждает инвалидацию по отпечатку). Тесты `tests/*Test.php` — без регрессий (класс логов в тестовый набор не входит, проверка только браузером).

## 5. Сохранение настроек — по SELECT + UPDATE на каждый лист дерева

`SettingProvider::setSetting()` рекурсивно спускается по дереву и на каждое конечное значение вызывает `SettingsModel::set()`, а тот делает `getByField()` и затем `insert()`/`updateByField()`. Дерево настроек витрины — несколько десятков листьев, плюс `custom_templates` по числу инстансов доставки/оплаты. Итого сотня-другая запросов на одно нажатие «Сохранить». Работает, но заметно; напрашивается `INSERT … ON DUPLICATE KEY UPDATE` — он же требует уникального ключа из [issue-57 §4](issue-57-minor-robustness-findings.md).

## 6. Шаблоны сводки = выполнение произвольного PHP (осознанный риск, задокументировать)

Webasyst использует Smarty 3.1.14 и **не** включает `enableSecurity()` — проверено по `wa-system/view/waSmarty3View.class.php`. В Smarty без политики безопасности модификатор — это любая PHP-функция: `{$x|shell_exec}`. Значит:

- `SettingsTemplatePreview` рендерит `waRequest::post('template')` как есть;
- сохранённый `summary_template` рендерится на витрине у каждого покупателя.

Оба пути закрыты проверкой `wa()->getUser()->isAdmin('shop')`, а CSRF снимается на уровне ядра (`'csrf' => true` в `wa-apps/shop/lib/config/app.php`), так что дыры здесь нет — уровень доступа тот же, что у штатного редактора тем Webasyst. Но это стоит явно написать в документации плагина: «редактор шаблонов доступен только администратору магазина и по возможностям равен редактору темы». Иначе вопрос всплывёт на модерации в Webasyst Store.

## 7. Cookies плагина без `SameSite` на стороне PHP

`prefill_guest_hash`, `prefill_consent`, `prefill_zen_*`, `prefill_user_selected` ставятся через `waResponse::setCookie()` без атрибута `SameSite` (в JS для `prefill_zen_*` и `prefill_user_selected` он как раз указан — `SameSite=Lax`). Браузеры применят `Lax` по умолчанию, так что поведение совпадёт, но разнобой между PHP и JS для одной и той же куки лучше убрать. Смежно с [issue-57 §2](issue-57-minor-robustness-findings.md) (`secure`).

## 8. `zenmode.css` подключается `<link>`-тегом из середины HTML

`CheckoutHooks::renderZenModeStylesheet()` возвращает `<link rel="stylesheet">` внутри секции auth, то есть в `<body>`. Браузеры это принимают, но такой стиль грузится блокирующе и уже после начала отрисовки формы — возможен заметный «прыжок» вёрстки при сворачивании блоков. Логичнее подключать его в `frontend_head` вместе с остальными ассетами (см. [issue-64](issue-64-assets-loaded-on-every-page.md), где ассеты как раз предлагается ограничить страницей чекаута).
