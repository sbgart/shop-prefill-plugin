# Issue 80 — Мелкие находки по фронтенду и админке (третий проход)

**Статус:** ⬜ Открыта
**Приоритет:** 🟢 Низкий
**Сложность фикса:** 🔧 Тривиальные, независимые

## 1. Бандл жёстко зависит от глобального jQuery, без единой проверки

`PrefillFrontendController.init()` вызывает `paramsChoiceManager.init()`, `orderFormManager.init()` и `consentManager.init()` — все три начинаются с `$(document).on(...)`. Если `$` на странице нет, конструктор падает с `ReferenceError`, `window.prefill` не создаётся, весь функционал плагина мёртв, а в консоли покупателя висит ошибка.

Пока бандл грузится на всех страницах витрины (см. [issue-64](issue-64-assets-loaded-on-every-page.md)), под удар попадают и те страницы, где тема могла jQuery не подключать. Достаточно guard'а в начале `init()` с записью в лог.

## 2. Ложное «Выбранный способ доставки недоступен»

```php
// CheckoutHooks::renderDeliveryUnavailableScript()
if ($state->getShippingType() !== '') { … }
return '<script>$(document).trigger("prefill_delivery_unavailable");</script>';
```

`getShippingType()` читает `data.shipping.selected_variant.type`. Этот ключ отсутствует не только когда вариант недоступен, но и когда шаг вообще не дошёл до расчёта: `shopCheckoutDetailsStep::process()` в «параноидальных проверках» возвращает `can_continue = false` при пустом `selected_variant`, и то же бывает на fast_render-ответе. То есть после `apply-delivery` + `location.reload()` покупатель может получить пугающий диалог там, где всё в порядке.

Стоит различать «вариант посчитан и не подошёл» и «расчёт ещё не выполнялся» — например, сигналить только когда шаг доставки реально отработал (`error_step_id !== 'shipping'` и есть `vars.shipping`).

## 3. Мёртвая инфраструктура настроек

`lib/config/setting_groups.php` возвращает `[]`, а `shopPrefillPluginAbstractArraySettingGroup` не имеет ни одного наследника. Ветка `$`-префикса в `shopPrefillPluginSettingsConfig::group()` при этом живёт и всегда уходит в фолбэк. Либо удалить, либо использовать — например, как раз для `custom_templates`, где ключ = ID инстанса плагина и его стоило бы валидировать. Смежно с [issue-71](issue-71-dead-code-in-release-archive.md).

## 4. Настройки никогда не удаляются

`SettingsModel` умеет только `set()`. Когда администратор удаляет инстанс доставки или оплаты, строки `zen.groups.delivery.custom_templates.<id>.*` остаются в `shop_prefill_settings` навсегда: UI рисует только существующие методы, значит в POST их больше нет, а раз нет — их никто и не перезапишет. ID из `shop_plugin` не переиспользуются, так что к чужому методу шаблон не прилипнет, но таблица растёт, и `parse()` каждый раз собирает дерево из мусора. Нужен `delete()` по префиксу groups при сохранении.

## 5. `$_locale` в админке: четыре ключа без переводов, три из них не используются

`templates/actions/settings/blocks/Head.html` объявляет `Error`, `Save`, `On`, `Off`, `dialog.css_reset.confirm`. Первых четырёх нет ни в `ru_RU`, ни в `en_US` `.po` — `_wp()` вернёт исходную строку, то есть в русской админке они были бы английскими. Реально из `window.$_()` используется только `dialog.css_reset.confirm` (`prefill.settings.js:497`), остальные — мёртвые. Удалить или перевести.

## 6. Мёртвые msgid в локалях

Сверка ключей кода с `.po` (347 используемых против 353 в каждой локали) даёт девять неиспользуемых: `Delivery plugin name`, `Formatted shipping cost`, `Name of the shipping method in store settings`, `Payment upon receipt`, `Pickup point`, `Store pickup`, `menu.contacts`, `menu.contacts.close`, `setting.section.placeholder` (+ одна пустая строка). Пропущенных переводов и незаполненных `msgstr` нет, `.mo` собраны и совпадают по числу записей — в остальном локали в порядке.

## 7. `ace.config.set('basePath', …)` вызывается после `ace.edit()`

В обеих инициализациях (`prefillZenTemplateAceInit`, `prefillCssAceInit`) `basePath` задаётся уже после создания редактора. Для тем и режимов, которые Ace догружает лениво, это гонка: в CSS-редакторе `setMode('ace/mode/css')` идёт позже и потому работает, а вот `editor.setTheme()` вызывается сразу после `ace.edit()` — то есть до установки пути. Достаточно переставить `ace.config.set()` перед `ace.edit()`.
