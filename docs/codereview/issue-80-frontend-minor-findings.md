# Issue 80 — Мелкие находки по фронтенду и админке (третий проход)

**Статус:** ✅ Закрыта (все пункты исправлены)
**Приоритет:** 🟢 Низкий
**Сложность фикса:** 🔧 Тривиальные, независимые

## 1. Бандл жёстко зависит от глобального jQuery, без единой проверки ✅ Исправлено

`PrefillFrontendController.init()` вызывает `paramsChoiceManager.init()`, `orderFormManager.init()` и `consentManager.init()` — все три начинаются с `$(document).on(...)`. Если `$` на странице нет, конструктор падает с `ReferenceError`, `window.prefill` не создаётся, весь функционал плагина мёртв, а в консоли покупателя висит ошибка.

Пока бандл грузится на всех страницах витрины (см. [issue-64](issue-64-assets-loaded-on-every-page.md)), под удар попадают и те страницы, где тема могла jQuery не подключать. Достаточно guard'а в начале `init()` с записью в лог.

## 2. Ложное «Выбранный способ доставки недоступен» ✅ Исправлено

```php
// CheckoutHooks::renderDeliveryUnavailableScript()
if ($state->getShippingType() !== '') { … }
return '<script>$(document).trigger("prefill_delivery_unavailable");</script>';
```

`getShippingType()` читает `data.shipping.selected_variant.type`. Этот ключ отсутствует не только когда вариант недоступен, но и когда шаг вообще не дошёл до расчёта: `shopCheckoutDetailsStep::process()` в «параноидальных проверках» возвращает `can_continue = false` при пустом `selected_variant`, и то же бывает на fast_render-ответе. То есть после `apply-delivery` + `location.reload()` покупатель может получить пугающий диалог там, где всё в порядке.

Стоит различать «вариант посчитан и не подошёл» и «расчёт ещё не выполнялся» — например, сигналить только когда шаг доставки реально отработал (`error_step_id !== 'shipping'` и есть `vars.shipping`).

**Подтверждено и исправлено.** `shopCheckoutViewHelper::formVars()` безусловно проставляет `input['fast_render'] = true` на каждом полном рендере `/shop/order/`; `shopCheckoutShippingStep::process()` при этом выходит до вычисления `data.shipping.selected_variant`, оставляя в `errors` только служебный сентинел `['fast_render' => true]` (тот же, что уже фильтровался в `getRegularErrors()`). `prepareFormVars()` тем не менее безусловно прогоняет `checkout_render_confirm` для этого ответа — то есть баг воспроизводится систематически на каждом `apply-delivery` + `location.reload()`, а не изредка.

Добавлен `shopPrefillCheckoutState::isFastRender()` (переиспользует сентинел-проверку из `getRegularErrors()`), и `renderDeliveryUnavailableScript()` теперь пропускает сигнал, если шаг shipping в этом ответе не считался — куку не гасит, следующий рендер (фоновый `calculate` после fast_render) досчитает по-настоящему. Проверено логом на реальном сценарии (apply-delivery по карточке из истории → reload): первый рендер — `shipping_type=""`, `is_fast_render=true`, `error_step_id="shipping"` (до фикса здесь ушёл бы ложный сигнал); второй рендер (фоновый calculate) — `shipping_type="todoor"`, `is_fast_render=false`, кука гасится штатно. Юнит-тест: `tests/CheckoutStateFastRenderTest.php`.

## 3. Мёртвая инфраструктура настроек ✅ Исправлено

`lib/config/setting_groups.php` возвращает `[]`, а `shopPrefillPluginAbstractArraySettingGroup` не имеет ни одного наследника. Ветка `$`-префикса в `shopPrefillPluginSettingsConfig::group()` при этом живёт и всегда уходит в фолбэк. Либо удалить, либо использовать — например, как раз для `custom_templates`, где ключ = ID инстанса плагина и его стоило бы валидировать. Смежно с [issue-71](issue-71-dead-code-in-release-archive.md).

**Подтверждено и удалено.** Механизм был перенесён из плагина `minorder`, но здесь ни разу не задействован: ни один конфиг (`settings.php`, `storefront.settings.php`) не объявляет ключ с префиксом `$`. Единственный правдоподобный кандидат, `custom_templates`, на деле объявлен обычным полем `['value' => []]` и пропускает массив без валидации по ключу — а ключи (`instance_id`) там и так приходят не от пользователя, а из `foreach` по реальным инстансам плагинов доставки/оплаты в самой admin-форме (`Zen.html`), так что валидировать по сути нечего. Удалены `lib/config/setting_groups.php` и `lib/classes/settings/groups/shopPrefillPluginAbstractArraySettingGroup.class.php`, из `shopPrefillPluginSettingsConfig` убраны параметр `$setting_groups` и мёртвая ветка `$`-префикса в `group()`. Юнит-тесты плагина (`tests/*Test.php`) проходят без изменений.

## 4. Настройки никогда не удаляются ✅ Исправлено

`SettingsModel` умеет только `set()`. Когда администратор удаляет инстанс доставки или оплаты, строки `zen.groups.delivery.custom_templates.<id>.*` остаются в `shop_prefill_settings` навсегда: UI рисует только существующие методы, значит в POST их больше нет, а раз нет — их никто и не перезапишет. ID из `shop_plugin` не переиспользуются, так что к чужому методу шаблон не прилипнет, но таблица растёт, и `parse()` каждый раз собирает дерево из мусора. Нужен `delete()` по префиксу groups при сохранении. Наверное тут же актуально и для витрин?

**Подтверждено и исправлено — для `custom_templates`.** Добавлен `shopPrefillPluginSettingsModel::deleteOrphanedGroups()` (удаление по префиксу `groups`, id решает чистая `shopPrefillPluginOrphanedGroupsFilter::filter()` — вынесена отдельно, чтобы разбор пути `groups` покрывался юнит-тестом без поднятия `waModel`). `shopPrefillPluginStorefrontSettingProvider::saveSettings()` на каждое сохранение витрины дергает `purgeOrphanedCustomTemplates()`: сверяет id под `zen.groups.{delivery,payment}.custom_templates` с `shopPluginModel::listPlugins($type, ['all' => true])` и удаляет то, чего в `shop_plugin` больше нет.

`'all' => true` здесь обязателен: `getShippingMethods()`/`getPaymentMethods()` (которыми рисуется сама форма) по умолчанию прячут ещё и просто отключённые (`status = 0`, не удалённые) инстансы — если бы очистка ориентировалась на них, выключение способа доставки стирало бы его шаблон так же, как удаление. Статус-агностичный `listPlugins(..., ['all' => true])` разводит эти два случая: удалён — чистим, отключён — не трогаем.

Живой прогон подтвердил обе стороны: инстанс, у которого `id` подменён на несуществующий (эмуляция удаления), после следующего сохранения настроек пропадает из `shop_prefill_settings`; тестовая пара строк для реально отключённого (`status = 0`), но не удалённого инстанса пережила то же сохранение без изменений. Попутно живой прогон вскрыл два бага в первой версии `deleteOrphanedGroups()`, не пойманных юнит-тестом на голом массиве: `groups` без бэктиков (зарезервированное слово MySQL, см. `project_mysql_groups_reserved_word` в памяти) ронял `saveSettings()` уже после того, как обычные поля были записаны, и `waModel::query()` отдаёт `waDbResultSelect`, а не `array`, что валило строго типизированный фильтр — оба пофикшены (`` `groups` `` в бэктиках, `->fetchAll()`). Юнит-тест: `tests/OrphanedGroupsFilterTest.php` (чистая логика фильтра); интеграционный путь через `waModel::query()` проверен только браузерным прогоном — стоит иметь в виду при следующей похожей правке в этой модели.

Про витрины (`storefront_code`-мусор при удалении/переименовании маршрута) — отдельная, более крупная проблема: у плагина нет собственного реестра витрин и, соответственно, нет события «витрина удалена», на которое можно было бы повесить очистку так же, как на `saveSettings()`. Осталась неисправленной, вынесена как отдельный вопрос, а не тихо расширена в рамках этого фикса.

## 5. `$_locale` в админке: четыре ключа без переводов, три из них не используются ✅ Исправлено

`templates/actions/settings/blocks/Head.html` объявляет `Error`, `Save`, `On`, `Off`, `dialog.css_reset.confirm`. Первых четырёх нет ни в `ru_RU`, ни в `en_US` `.po` — `_wp()` вернёт исходную строку, то есть в русской админке они были бы английскими. Реально из `window.$_()` используется только `dialog.css_reset.confirm` (`prefill.settings.js:497`), остальные — мёртвые. Удалить или перевести.

**Подтверждено и исправлено.** `grep '\$_('` по `js/` и `templates/` показал единственное реальное обращение — `dialog.css_reset.confirm` в `prefill.settings.js:539`. Остальные три ключа из `$_locale` удалены, объект оставлен с одной записью.

## 6. Мёртвые msgid в локалях ✅ Исправлено

Сверка ключей кода с `.po` (347 используемых против 353 в каждой локали) даёт девять неиспользуемых: `Delivery plugin name`, `Formatted shipping cost`, `Name of the shipping method in store settings`, `Payment upon receipt`, `Pickup point`, `Store pickup`, `menu.contacts`, `menu.contacts.close`, `setting.section.placeholder` (+ одна пустая строка). Пропущенных переводов и незаполненных `msgstr` нет, `.mo` собраны и совпадают по числу записей — в остальном локали в порядке.

**Подтверждено и исправлено.** Каждый из девяти ключей проверен `grep`'ом по коду и шаблонам отдельно от похожих используемых строк (например, `Formatted shipping cost` мёртв, а `Formatted shipping cost (HTML)` — реальный, используемый ключ; `menu.contacts` мёртв как отдельный ключ, хотя `menu.contacts.dialog.title` и другие ключи того же неймспейса используются в `ContactsDialog.html`). «Пустая строка» из подсчёта — это служебный `msgid ""` в заголовке `.po`, трогать не требовалось. Все девять блоков `msgid`/`msgstr` удалены из обеих локалей, `.po` синхронизированы через `php wa.php locale` (0 новых ключей, число слов совпало), `.mo` пересобраны `msgfmt`, кэш шаблонов и PHP-FPM сброшены. Проверено в браузере: страница настроек и диалог «Мы на связи» (использует соседние `menu.contacts.*` ключи) рендерятся без английских вкраплений и без ошибок в консоли.

## 7. `ace.config.set('basePath', …)` вызывается после `ace.edit()` ✅ Исправлено

В обеих инициализациях (`prefillZenTemplateAceInit`, `prefillCssAceInit`) `basePath` задаётся уже после создания редактора. Для тем и режимов, которые Ace догружает лениво, это гонка: в CSS-редакторе `setMode('ace/mode/css')` идёт позже и потому работает, а вот `editor.setTheme()` вызывается сразу после `ace.edit()` — то есть до установки пути. Достаточно переставить `ace.config.set()` перед `ace.edit()`.

**Подтверждено и исправлено.** Та же гонка нашлась и в третьей, не упомянутой в находке инициализации — `prefillCssReadonlyAceInit` (read-only редактор «Показать оригинальный CSS»). Во всех трёх функциях `ace.config.set('basePath', …)` переставлен перед `ace.edit()`. Проверено в браузере на всех трёх редакторах (общий шаблон Zen-блока «Доставка», CSS-редактор и его read-only просмотр оригинала): темы и подсветка синтаксиса (`ace/mode/smarty`, `ace/mode/css`) применяются корректно, ошибок в консоли нет.
