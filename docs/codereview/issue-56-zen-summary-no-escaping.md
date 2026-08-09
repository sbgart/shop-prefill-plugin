# Issue 56 — Данные покупателя выводятся в шаблонах без экранирования

**Статус:** ⬜ Открыта
**Приоритет:** 🟡 Средний
**Сложность фикса:** 🔧 Небольшой
**Файлы:** `lib/classes/zenmode/shopPrefillPluginZenMode.class.php` (`renderGroupSummary`), `lib/config/storefront.settings.php` (дефолтные `summary_template`), `templates/actions/frontend/FrontendParamsChoice.html`

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

## Рекомендация

1. Добавить `|escape` во все дефолтные `summary_template` в `storefront.settings.php` и в `FrontendParamsChoice.html`.
2. Поля, которые намеренно содержат HTML (`shipping_rate`, `delivery_schedule`, `delivery_photos_html`), оставить без `escape` и задокументировать это в редакторе шаблонов.
3. В подсказках редактора показывать сниппеты уже с `|escape` (в `snippet_loop` это уже сделано — привести остальные к тому же виду).
