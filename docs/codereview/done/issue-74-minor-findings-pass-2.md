# Issue 74 — Мелкие находки второго прохода ревью

**Статус:** ✅ Закрыта (8 из 8 — 25.08.2026)
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

## 5. Сохранение настроек — по SELECT + UPDATE на каждый лист дерева — ✅ закрыто

`SettingProvider::setSetting()` рекурсивно спускается по дереву и на каждое конечное значение вызывает `SettingsModel::set()`, а тот делает `getByField()` и затем `insert()`/`updateByField()`. Дерево настроек витрины — несколько десятков листьев, плюс `custom_templates` по числу инстансов доставки/оплаты. Итого сотня-другая запросов на одно нажатие «Сохранить». Работает, но заметно; напрашивается `INSERT … ON DUPLICATE KEY UPDATE` — он же требует уникального ключа из [issue-57 §4](issue-57-minor-robustness-findings.md).

Исправлено 24.08.2026 — без уникального ключа и без `ON DUPLICATE KEY UPDATE`: тот путь остаётся заблокирован ровно теми же причинами, по которым его отклонили в issue-57 §4 (MyISAM/utf8mb3, префиксный индекс на `groups` физически не влезает). Вместо этого — батчинг на уровне PHP:

- `shopPrefillPluginAbstractSettingProvider::flattenSettings()` — общий для обоих провайдеров рекурсивный обход дерева (вынесен из дублировавшихся `setSetting()`), принимает колбэк на лист. `setSetting()` как публичный API не тронут — по-прежнему пишет каждый лист сразу через `model->set()`; это нужно для единственного вызывающего вне `saveSettings()` — `shopPrefillPluginSettingsSaveLogLevelController`, который ожидает немедленную запись одного значения.
- `saveSettings()` в обоих провайдерах теперь копит листья в плоский массив через `flattenSettings()` и одним вызовом отдаёт `SettingsModel::setBulk()`.
- `SettingsModel::setBulk()` — 1 `SELECT` существующих строк по `storefront_code`, затем чистая `shopPrefillPluginSettingsBulkWritePlanner::plan()` (без обращений к БД, юнит-тест `tests/SettingsBulkWritePlannerTest.php`) делит листья на «вставить» / «обновить» по совпадению `(name, groups)`, дальше — один `multipleInsert()` на все новые строки и один `UPDATE … SET value = CASE id WHEN … END WHERE id IN (…)` на все обновляемые. Итого не больше 3 запросов на сохранение одной витрины вместо ~80–100.

Порядок операций внутри `saveSettings()` не менялся: `setBulk()` → `purgeOrphanedCustomTemplates()` (issue-80#4) → `syncCssFile()` — очистка осиротевших `custom_templates` по-прежнему видит уже записанные новые значения.

Проверено в браузере (счётчик `performance_schema.global_status Questions` до/после, `mariadb` MCP):

- Сохранение глобальных настроек (4 листа): 30 запросов на весь HTTP-запрос — почти целиком фреймворковый оверхед (роутинг, сессия, права), на саму таблицу приходится 2–3.
- Сохранение одной витрины (~40 листьев + `styles.accent_color` изменён на `#c93131`): тоже 30 запросов, значение записалось точно (`SELECT` подтвердил `#c93131`), число строк для этой витрины не изменилось (69 до/после — совпадений по `(name, groups)` не потеряно, дублей не создано).
- Multi-storefront путь issue-78 («сохранить все открытые», 3 витрины разом — глобальные + 2 витрины): 43 запроса на весь запрос вместо расчётных ~270 при старом поштучном `set()`. Дублей нет (`GROUP BY name, groups HAVING COUNT(*) > 1` — пусто), одна витрина закономерно получила +1 новую строку (лист, которого раньше не было — не дубль, проверено отдельным запросом).

Побочно всплыл не связанный с этой правкой нюанс: тумблер «Применять общие настройки для всех витрин» (`active` в глобальных настройках) при сохранении в выключенном состоянии продолжает писать в БД `true` — похоже на то, что чекбокс в OFF-состоянии не попадает в POST и `validate()` подставляет дефолт схемы. Не трогал — логика сборки `$settings` из POST и валидации схемой этой правкой не менялась, поведение идентично старому коду. Стоит завести отдельным пунктом, если понадобится доверенный дефолт `false`.

## 6. Шаблоны сводки = выполнение произвольного PHP (осознанный риск, задокументировать) — ✅ закрыто

Webasyst использует Smarty 3.1.14 и **не** включает `enableSecurity()` — проверено по `wa-system/view/waSmarty3View.class.php`. В Smarty без политики безопасности модификатор — это любая PHP-функция: `{$x|shell_exec}`. Значит:

- `SettingsTemplatePreview` рендерит `waRequest::post('template')` как есть;
- сохранённый `summary_template` рендерится на витрине у каждого покупателя.

Оба пути закрыты проверкой `wa()->getUser()->isAdmin('shop')`, а CSRF снимается на уровне ядра (`'csrf' => true` в `wa-apps/shop/lib/config/app.php`), так что дыры здесь нет — уровень доступа тот же, что у штатного редактора тем Webasyst. Но это стоит явно написать в документации плагина: «редактор шаблонов доступен только администратору магазина и по возможностям равен редактору темы». Иначе вопрос всплывёт на модерации в Webasyst Store.

Задокументировано 25.08.2026: абзац о модели доступа добавлен в [ADMIN-SETTINGS.md](../concept/ADMIN-SETTINGS.md#zen-mode-общий-и-пер-инстансный-шаблон-сводки) (раздел про `summary_template`/`custom_templates`), и короткий инвариант **B4** — в [RULES.md](../concept/RULES.md#границы), рядом с остальными границами доверия (B1–B3). Заодно поправлено расхождение с более старым [issue-54](done/issue-54-backend-actions-no-rights-check.md#L30): тот файл утверждал, что в Webasyst «включена политика безопасности Smarty» и `shell_exec` заблокирован — повторная проверка (`grep -rn "enableSecurity" wa-system wa-apps/shop`) не находит ни одного вызова вне самого класса Smarty, то есть заявление было ошибочным. В issue-54 добавлена поправка от 24.08.2026 со ссылкой на актуальный разбор; вывод задачи («вынести проверку прав в базовый класс») не изменился — RCE не открывает новую дыру именно потому, что единственная и достаточная защита это `isAdmin('shop')`, который issue-54 и добавил.

## 7. Cookies плагина без `SameSite` на стороне PHP — ✅ закрыто

`prefill_guest_hash`, `prefill_consent`, `prefill_zen_*`, `prefill_user_selected` ставятся через `waResponse::setCookie()` без атрибута `SameSite` (в JS для `prefill_zen_*` и `prefill_user_selected` он как раз указан — `SameSite=Lax`). Браузеры применят `Lax` по умолчанию, так что поведение совпадёт, но разнобой между PHP и JS для одной и той же куки лучше убрать. Смежно с [issue-57 §2](issue-57-minor-robustness-findings.md) (`secure`).

Исправлено 25.08.2026: `prefill_consent` и `prefill_guest_token` (переименована из `prefill_guest_hash`) уже были переведены на array-style `setCookie()` с явным `'samesite' => 'Lax'` в issue-57 §2 — по тому же паттерну переведены оставшиеся вызовы:

- `ZenMode::syncCollapseCookieState()` и `ZenMode::clearCookies()` (`prefill_zen_*`, `lib/classes/zenmode/shopPrefillPluginZenMode.class.php`);
- `CheckoutHooks::renderDeliveryUnavailableScript()` (`prefill_user_selected`, `lib/classes/hooks/shopPrefillPluginCheckoutHooks.class.php`).

`waResponse::setCookie()` (`wa-system/response/waResponse.class.php:110-118`) поддерживает `SameSite` только через array-style `$expires`; при позиционном вызове без него ядро само подставляет `Lax`/`None` в зависимости от HTTPS — поведение при фиксе не изменилось, ушла только несогласованность кода. `httponly` для `prefill_zen_*` осознанно оставлен `false` — куку читает `ZenModeToggle.js`.

Заодно поправлена находка вне списка issue: `ParamsChoiceManager.js:159` чистил `prefill_user_selected` без `SameSite=Lax`, хотя установка (строка 82) его указывает — несогласованность была внутри самого JS, не только между PHP и JS. Добавлено, минифицированный бандл (`js/prefill.frontend.min.js`) пересобран скиллом `build-plugin-frontend`.

**Не тронуто осознанно** (вне заявленного в issue списка кук, того же класса находка): `auth_token` (`UserProvider.class.php`) и `dp_plugin_*`/`prefill_dp` (`Integrations.class.php`) — тоже без `SameSite`, а `dp_plugin_*` вдобавок без `secure`/`httponly`. Стоит завести отдельным пунктом, `dp_plugin_*` по характеру ближе к issue-57, чем к этому issue.

Проверено гостевым curl-сценарием (`/order/` с товаром в корзине): `Set-Cookie: prefill_zen_customer=expanded; path=/; SameSite=Lax` и аналогично для `delivery`/`payment` — подтверждено прямо в заголовках ответа. Прогнан весь набор `tests/*Test.php` — без регрессий.

## 8. `zenmode.css` подключается `<link>`-тегом из середины HTML — ✅ закрыто

`CheckoutHooks::renderZenModeStylesheet()` возвращает `<link rel="stylesheet">` внутри секции auth, то есть в `<body>`. Браузеры это принимают, но такой стиль грузится блокирующе и уже после начала отрисовки формы — возможен заметный «прыжок» вёрстки при сворачивании блоков. Логичнее подключать его в `frontend_head` вместе с остальными ассетами (см. [issue-64](issue-64-assets-loaded-on-every-page.md), где ассеты как раз предлагается ограничить страницей чекаута).

Исправлено 25.08.2026: `renderZenModeStylesheet()` и её вызов из `buildZenModeGroupBlock()` (печатался до трёх раз — из auth/delivery/payment секций) удалены целиком вместе со ставшими лишними `plugin_static_url`/`plugin_version` в конструкторе `CheckoutHooks`. Подключение перенесено в `FrontendHooks::initializeFrontendAssets()` (тот же `frontend_head`, что и остальные ассеты плагина, issue-64) через уже существующий `add_css_callback` → `waPlugin::addCss()` — автоматическое версионирование вместо ручного `rawurlencode($this->plugin_version)`, попадание в `<head>` вместо ручной сборки тега.

Условие подключения — новый публичный метод `ZenMode::hasAnyGroupEnabled()` (перебирает `customer`/`delivery`/`payment` через уже существующий `isGroupEnabled()`), внедрённый в `FrontendHooks` как четвёртая зависимость (`shopPrefillPlugin::getZenMode()`, без циклических зависимостей — `getZenMode()` не обращается к `getFrontendHooks()`). Условие по факту чуть шире прежнего (раньше тег печатался только для секции, где реально рендерился блок конкретной группы; теперь — если активна хоть одна из трёх) — в худшем случае лишние ~2.7 КБ CSS без единого потребителя на странице, не утечка данных.

Проверено гостевым curl-сценарием (`/order/`, Zen Mode активен на витрине `wa-dev.loc/*`, группы `customer`/`delivery`/`payment` включены — сверено в БД): `zenmode.css` встречается в ответе **ровно один раз**, на строке до `</head>`, с версионированным URL (`zenmode.css?1.0.0.<timestamp>` — debug-формат). До фикса тег печатался бы до трёх раз посреди `<body>`. Прогнан весь набор `tests/*Test.php` — без регрессий.
