# Issue 74 — Мелкие находки второго прохода ревью

**Статус:** ⬜ Открыта
**Приоритет:** 🟢 Низкий
**Сложность фикса:** 🔧 Тривиальные, независимые друг от друга

Продолжение [issue-57](issue-57-minor-robustness-findings.md). Каждый пункт самостоятельный.

## 1. Общий `waView` загрязняется без восстановления

`ZenMode::renderGroupSummary()` аккуратно сохраняет и возвращает переменные вида (`$old_vars` + `clearAssign`). А вот два соседних места пишут в тот же singleton-view и ничего не восстанавливают:

- `ZenMode::renderCollapseBlock()` — `group`, `is_collapsed`, `icon_url`, `icon_is_default`, `icon_sprite_url`, `summary_html`, `zen_toggle_button_extra_classes`;
- `ViewProvider::render()` — `plugin_url` + всё, что передали (для чекбокса согласия — `has_consent`).

В шаблонах ядра Shop-Script коллизий по этим именам нет (проверено grep'ом по `templates/actions/frontend/`), но `plugin_url` и `group` — имена, которые вполне может занять сторонняя тема или другой плагин. Стоит привести все три места к одному паттерну (проще всего — сохранять/возвращать `getVars()`, как в `renderGroupSummary`).

## 2. `waRequest::post('code')` без типа → `TypeError` вместо 400

`shopPrefillPluginSettingsStorefrontAction::handle()`:

```php
$storefront_code = waRequest::post('code');
…
$storefront = $plugin->getStorefrontProvider()->findStorefront($storefront_code);
```

`findStorefront(?string $storefront_code)` при массиве в `code` получит `TypeError` — а это `Error`, не `Exception`, ловилок нет → 500. Лечится `waRequest::TYPE_STRING_TRIM` (как это сделано в `TemplateEditor`). Тот же приём стоит применить в `FillCheckoutParams`, если контроллер не удалят по [issue-62](issue-62-dead-unguarded-fill-checkout-endpoint.md).

## 3. Undefined index при заказе с регионом без страны

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

## 4. Просмотрщик логов перечитывает оба файла целиком на каждую страницу пагинации

`SettingsReadLogs::handle()` → `LogReader::readMerged()` читает до 1 МБ с хвоста каждого файла (+ ротированные поколения), парсит регулярками **все** записи, сортирует `usort`'ом — и только потом делает `array_slice($all_reversed, $offset, $limit)`. При скролле на 10 страниц это 10 полных разборов ~2 МБ. Админский путь, некритично, но при активном дебаге заметно. Вариант: кэшировать распарсенный массив в сессии/файле по mtime логов.

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
