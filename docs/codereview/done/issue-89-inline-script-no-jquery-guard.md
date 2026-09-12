# issue-89. Инлайновый `<script>` сигнала о недоступной доставке не защищён гардом jQuery

**Статус:** исправлено 28.08.2026 (в тот же день, что и найдено).
**Приоритет:** 🟢 мелочь и гигиена.

---

## Суть

`shopPrefillPluginCheckoutHooks::renderDeliveryUnavailableScript()`, [строка 277](../../lib/classes/hooks/shopPrefillPluginCheckoutHooks.class.php):

```php
return '<script>$(document).trigger("prefill_delivery_unavailable");</script>';
```

Это единственное место в плагине, где `$` используется без проверки. В issue-80 §1 такой гард был осознанно добавлен в точке входа фронтенда — `PrefillFrontendController::init()`:

```js
if (typeof $ === "undefined") {
  this.logger.error("jQuery ($) is not available on this page, prefill frontend is disabled.");
  return;
}
```

Но этот инлайн живёт **не внутри контроллера** — он приезжает разметкой из PHP-хука `checkout_render_confirm` и выполняется сам по себе, мимо `init()`. Тема, не подключающая jQuery, получит `Uncaught ReferenceError: $ is not defined` в консоли на странице оформления заказа.

## Почему всё-таки 🟢, а не выше

- Ошибка изолирована: инлайн-скрипт падает сам, ничего вокруг не ломая. Форма ядра работает, разметка плагина на месте.
- Сам сигнал в такой теме всё равно бесполезен — его слушает `ParamsChoiceManager`, который в отсутствие jQuery не инициализировался.
- Ветка редкая: скрипт эмитится только когда стоит кука `prefill_user_selected`, доставка не заполнилась и это не `fast_render`.
- Практически: чекаут2 Shop-Script сам держится на jQuery (`form.js`), так что тема без него — экзотика.

Именно поэтому это гигиена, а не блокер: чинить стоит ради консистентности с issue-80, а не ради работоспособности.

## Фикс

Применено в [shopPrefillPluginCheckoutHooks.class.php:277](../../lib/classes/hooks/shopPrefillPluginCheckoutHooks.class.php#L277) — та же проверка, что и в `PrefillFrontendController::init()` (issue-80 §1):

```php
return '<script>if(typeof $!=="undefined"){$(document).trigger("prefill_delivery_unavailable");}</script>';
```

Проверено: `php -l` чист; собранная строка прогнана через `node -e` с `$` намеренно не определён — раньше кинуло бы `ReferenceError`, теперь молчаливый no-op, как и задумано. Живой браузерный репро не делался — ветка требует одновременно куку `prefill_user_selected=1`, пустой `shipping_type` и не-`fast_render` рендер, плюс тему без jQuery, чего на стенде нет; риск и так признан низким (см. «Почему всё-таки 🟢» выше).

## Смежное наблюдение (не issue)

`shopPrefillPluginDebug` тоже отдаёт инлайновые `<script>` без гарда — но он выводится только при `waSystemConfig::isDebug()` и включённой настройке `prefill.debug_panel`, то есть на витрине магазина не появляется. Трогать не нужно.
