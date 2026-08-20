# Issue 56 — Данные покупателя выводятся в шаблонах без экранирования

**Статус:** ✅ Решена
**Приоритет:** 🟡 Средний
**Сложность фикса:** 🔧 Небольшой
**Файлы:** `lib/classes/zenmode/shopPrefillPluginZenSummaryEscaper.class.php` (новый), `lib/classes/zenmode/shopPrefillPluginZenData.class.php`, `lib/actions/shopPrefillPluginSettingsTemplateEditor.action.php`, `templates/actions/frontend/FrontendParamsChoice.html`

## Проблема

В Webasyst `Smarty::$escape_html = false` (проверено), автоэкранирования нет. При этом:

- дефолтные шаблоны сводки Zen Mode выводят пользовательские значения без фильтра:
  `{$firstname} {$lastname} • {$phone}`, `{$city}{if $street}, {$street}{/if}, {$building}, {$apartment}`;
- диалог «Мои варианты» выводит адреса так же: `{$fill_params.street}`, `{$fill_params.city}`, `{$fill_params.shipping_name}`.

Значения приходят из контакта/прошлых заказов, то есть могут содержать HTML, введённый самим покупателем (или администратором при правке заказа). Итог — HTML/JS исполняется на странице оформления заказа.

## Оценка риска

Это self-XSS: покупатель видит только свои данные, чужие сессии не затрагиваются. Кража чужих данных так не делается. Но:

- «поплывёт» вёрстка чекаута от случайных `<`, `>` в адресе;
- сценарий «админ правит комментарий заказа → покупатель получает HTML» уже не совсем self;
- для магазина на продажу проверяющие обычно требуют экранирование по умолчанию.

Отмечу, что в местах, где HTML строится в PHP (`buildPhotosHtml`, `renderPickupScheduleDays`, `renderErrorsDebugHtml`), `htmlspecialchars` применён корректно — проблема только в Smarty-шаблонах.

## Решение

Исходная рекомендация была «добавить `|escape` в дефолтные `summary_template`». От неё отказались: шаблон сводки **редактирует магазин** и хранит его в БД (`shop_prefill_settings`, `summary_template` и `custom_templates[*]`). `|escape` в дефолтах закрыл бы дыру только на свежей установке — всё, что магазин сохранит сам, осталось бы незащищённым, а безопасность держалась бы на дисциплине автора шаблона.

Экранируем **в данных**, в единственной точке, через которую переменные попадают в любой шаблон:

1. `shopPrefillPluginZenSummaryEscaper` — рекурсивно экранирует строки (`htmlspecialchars`, `ENT_QUOTES`, `UTF-8`) вместе с ключами массивов; не-строковые скаляры не трогает, чтобы не ломать `{if}`-условия.
2. `shopPrefillPluginZenData::extractSummaryData()` прогоняет через него результат; `getSampleData()` — тоже, чтобы превью в админке совпадало с витриной.
3. Поля с контрактом HTML помечены `'is_html' => true` в `getAvailableFields()` — тот же источник правды, что и для UI редактора: `shipping_rate`, `delivery_schedule`, `delivery_photos_html` (собирает сам плагин, внутри уже экранировано) и `delivery_description`, `payment_description`, `service_agreement_hint` (контент администратора, ядро выводит его сырым — `order/form/payment.html:55`, `details.html:98`, `auth.html:141`).
4. В подсказках редактора такие поля несут пометку «Содержит HTML и выводится без экранирования» (`zen.custom_template.html_field_note`).
5. `|escape` убран из `snippet_loop` — данные уже экранированы, второй проход дал бы `&amp;lt;`.
6. Диалог «Мои варианты» идёт мимо ZenData, поэтому `FrontendParamsChoice.html` экранирует у себя.

Дефолтные шаблоны в `storefront.settings.php` намеренно оставлены **без** `|escape`; рядом стоит комментарий, почему.

## Проверка

`tests/ZenSummaryEscapeTest.php` — экранирование скаляров, ключей и значений вложенных массивов, неприкосновенность HTML-полей, сохранность типов и состава ключей.

Браузерные сценарии (8 штук, ✅ 20.08.2026) — в [TESTS.md](../TESTS.md), раздел «Экранирование данных покупателя (Z7)». Правило — Z7 в [RULES.md](../concept/RULES.md).
