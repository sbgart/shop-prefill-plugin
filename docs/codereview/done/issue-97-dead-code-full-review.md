# Issue 97 — Мёртвый код полного ревью: 14 методов без вызывающих и один вызов несуществующего метода

**Статус:** ✅ Закрыта 11.09.2026 — все 10 методов основной таблицы и шим `attachDialogCloseHandler()` удалены. `hasDataForSection()` оставлен, как и планировалось, с добавленной ссылкой на этот issue.
**Приоритет:** 🟢 Мелочь и гигиена
**Сложность фикса:** 🔧 Небольшой
**Файлы:** см. таблицу

## Как это работает сейчас

Прогон по всем `function` в `lib/` с проверкой упоминаний по `lib/`, `templates/`, `js/`, `css/`,
`tests/` и `docs/` (кроме `docs/codereview/`, где имена встречаются в разборах):

| Файл | Метод | Комментарий |
|---|---|---|
| `lib/shopPrefill.plugin.php` | `clearEffectiveStorefrontCache()` | Задумывался для сброса после сохранения настроек — не зовётся ниоткуда |
| `lib/shopPrefill.plugin.php` | `getPluginsProvider()` | Единственный вызов был в `ParamsChoiceAction` и удалён (см. `docs/concept/checkout_address_selection.md:259`). Провайдер везде используется статически |
| `lib/classes/zenmode/shopPrefillPluginZenMode.class.php` | `getGroupSections()` | Карта читается напрямую из константы `GROUP_SECTIONS` |
| `lib/classes/zenmode/shopPrefillPluginZenMode.class.php` | `getGroups()` | То же |
| `lib/classes/zenmode/shopPrefillPluginZenMode.class.php` | `renderGroupSummary()` | Публичный; после разделения на `resolveSummaryData()` + `renderSummaryFromData()` вызывающих не осталось. Упоминается только в собственном сообщении лога и в `docs/bugs/zen-collapse-on-upstream-checkout-error.md` как описание архитектуры |
| `lib/classes/fillparams/shopPrefillPluginGuestTokenStorage.class.php` | `hasToken()` | Все вызывающие спрашивают `getToken() !== null` или `getParamName()` |
| `lib/classes/fillparams/shopPrefillPluginGuestTokenStorage.class.php` | `getTokenCookieName()` | Статический геттер приватной константы, ни одного вызова |
| `lib/classes/fillparams/shopPrefillPluginFillParams.class.php` | `getShippingPlugin()` | Сеттера-пары нет по [замыслу](../../plans/done/delivery-variant-identity.md) — поле не заполняется намеренно, значит и геттер бессмыслен |
| `lib/classes/fillparams/shopPrefillPluginFillParams.class.php` | `getPaymentPlugin()` | Поле заполняется (`setPaymentPlugin()` в `FillParamsProvider`), но никто не читает |
| `lib/classes/orders/shopPrefillPluginOrderProvider.class.php` | `getOrderIdsByGuestParam()` | Гостевая история исключена из «Моих вариантов» в [issue-55](issue-55-guest-apply-delivery-ignores-order-id.md) — метод остался от неё |
| `lib/classes/fillparams/shopPrefillPluginFillParams.class.php` | `hasDataForSection()` | **Оставить.** Явно зарезервирован в [плане](../../plans/done/delivery-variant-identity.md) как примитив для issue-84 §2 |

Отдельно — не мёртвый, а сломанный вызов:

```js
// js/prefill.frontend.js:115-119
/**
 * @deprecated Используйте this.dialogManager.attachCloseHandler()
 */
attachDialogCloseHandler(dialog, closeButton) {
  this.dialogManager.attachCloseHandler(dialog, closeButton);
}
```

Метода `attachCloseHandler()` у `DialogManager` нет — в классе есть только приватный `_attachEvents()`
(`js/modules/DialogManager.js:154, 164`). Шим объявлен «для обратной совместимости», но вызов упал бы
`TypeError`-ом у любого, кто им воспользуется.

## Что из этого следует

Сегодня не проявляется ничем: недостижимый код не исполняется. Значение находки — в двух вещах.

Во-первых, `exclude.php` вычищает из релизного архива `docs`, `tests` и `*.md`, но не мёртвые методы —
всё перечисленное уезжает покупателю (тот же довод, что в [issue-71](issue-71-dead-code-in-release-archive.md)).

Во-вторых, три пункта — не забытые огрызки, а следы завершённых миграций: `renderGroupSummary()`
пережил разделение сборки и рендера сводки, `getOrderIdsByGuestParam()` — исключение гостевой истории,
`getPluginsProvider()` — чистку `ParamsChoiceAction`. Пока они на месте, следующий читатель считает
их частью работающей схемы: `renderGroupSummary()` выглядит вторым, независимым путём рендера сводки,
которого на самом деле нет.

Шим `attachDialogCloseHandler()` вреднее остальных: он не мёртв, а обещает работающий публичный API.

## Рекомендация

1. Удалить десять методов из таблицы (`hasDataForSection()` оставить, комментарий о резерве в нём уже
   есть — стоит дополнить ссылкой на этот issue, чтобы следующая уборка его не снесла).
2. Удалить `attachDialogCloseHandler()` из `prefill.frontend.js` вместе с остальными `@deprecated`-шимами,
   если у них тоже нет внешних пользователей, — либо реализовать `attachCloseHandler()` в
   `DialogManager`, если шим считается публичным API.
3. Перед удалением каждого имени — грепнуть по `lib/`, `templates/`, `js/`, `css/`, `locale/`, `docs/`
   (проверка уже выполнена на дату находки, но между находкой и фиксом код меняется).
4. Бандл после правки `prefill.frontend.js` пересоберётся штатно в `/release-pack`.

## Дополнение 09.09.2026 (повторный полный проход): ещё три метода

Прежний прогон считал упоминания имени по всему дереву, поэтому пропустил методы, чьё
**единственное** упоминание — вызов из своего же класса или совпадение имени с методом ядра.
Пересчёт по фактическим вызовам объекта (`$storefront->…`) даёт ещё три:

| Файл | Метод | Комментарий |
|---|---|---|
| `lib/classes/storefronts/shopPrefillPluginStorefront.class.php` | `setSetting()` | Ни одного вызова. Единственный путь записи настроек витрины — `saveSettings()` (из `shopPrefillPlugin::saveSettings()`) |
| `lib/classes/settings/providers/shopPrefillPluginStorefrontSettingProvider.class.php` | `setSetting()` | Единственный вызывающий — мёртвый `Storefront::setSetting()` выше. У глобального провайдера одноимённый метод жив: его зовёт `shopPrefillPluginSettingsSaveLogLevelController` |
| `lib/classes/storefronts/shopPrefillPluginStorefront.class.php` | `getRouteUrl()` | Ни одного вызова. Грепом ловится только `wa()->getRouteUrl()` в `shopPrefillPluginDebug` — это метод ядра, не этот |

Отдельно важен первый пункт: пока `StorefrontSettingProvider::setSetting()` жив, у настроек
витрины формально два пути записи, и гейт issue-96 (`stripTemplateWritesForNonAdmin()`) стоит
только на одном из них — на `saveSettings()`. Сегодня это безопасно ровно потому, что второй
путь недостижим. Удаление обоих `setSetting()` превращает «недостижимо» в «не существует» и
снимает вопрос при следующем ревью прав.

**Проверка перед удалением** (та же, что в п. 3 рекомендаций, но по вызовам, а не по имени):

```bash
grep -rn -e '->setSetting(' -e '->getRouteUrl(' lib templates js
```

— должны остаться только `SaveLogLevelController` и `wa()->getRouteUrl()`.

**Фикс (09.09.2026):** все три метода дополнения удалены — `Storefront::setSetting()`,
`Storefront::getRouteUrl()` и `StorefrontSettingProvider::setSetting()`. Проверка вызовов
после удаления (`grep -rn -e '->setSetting(' -e '->getRouteUrl(' lib templates js`) отдаёт
ровно ожидаемое: `$provider->setSetting('logging', …)` в `SaveLogLevelController` (это
`shopPrefillPluginSettingProvider` — глобальный провайдер, другой класс, жив) и
`wa()->getRouteUrl('shop/frontend')` в `shopPrefillPluginDebug` (метод ядра). `php -l` чист,
все 20 тестов зелёные.

Основная таблица (11 методов) и шим `attachDialogCloseHandler()` остаются в бэклоге как
изначально заведено — этот раздел не блокирует релиз и не входит в область точечного фикса
issue-99…101.

## Фикс (11.09.2026)

Перед удалением каждое имя перепроверено свежим грепом по вызовам (`->name(`/`::name(`) в `lib/`,
`templates/`, `js/`, `css/`, `locale/` — код мог измениться с 09.09.2026, но состав не изменился.

Удалено 10 методов основной таблицы: `clearEffectiveStorefrontCache()`, `getPluginsProvider()`
(вместе с ним — неиспользуемое поле `$plugins_provider`, единственный писавший/читавший его метод),
`ZenMode::getGroupSections()`, `ZenMode::getGroups()`, `ZenMode::renderGroupSummary()`,
`GuestTokenStorage::hasToken()`, `GuestTokenStorage::getTokenCookieName()`,
`FillParams::getShippingPlugin()` (сеттер остался — используется), `FillParams::getPaymentPlugin()`
(сеттер остался — используется), `OrderProvider::getOrderIdsByGuestParam()`. Попутно поправлены два
комментария, ссылавшихся на удалённый `renderGroupSummary()` (докблок `resolveSummaryData()` и
сообщение об ошибке рендера в `renderSummaryFromData()`) — указывают на реальных вызывающих.

`hasDataForSection()` оставлен как и планировалось; в его докблок добавлена ссылка на этот issue,
чтобы следующая уборка мёртвого кода не снесла резерв под issue-84 §2.

**`clearEffectiveStorefrontCache()` — отдельно проверено, не забытый вызов.** Докблок метода и
строка в `CLAUDE.md` обещали чистку кэша «после сохранения настроек», что похоже на потерянный
вызов, а не на честный мёртвый код. Агентом-ресёрчем проверена вся цепочка: `saveSettings()`
вызывается только из стандартного `waPluginsActions::saveAction()` ядра, который сразу отдаёт JSON и
ничего не перечитывает; единственный нефронтендовый читатель кэша (`controllerBefore`) выходит по
`wa()->getEnv() !== 'frontend'` раньше, чем добрался бы до кэша, а бэкенд-сохранение настроек идёт
именно в `backend`-окружении. Устаревать в границах одного запроса нечему — а в PHP-FPM статические
поля и так обнуляются между запросами, так что метод был не забытым вызовом, а защитой от сценария,
структурно невозможного в этой рантайм-модели. Строка из `CLAUDE.md` тоже убрана.

**`attachDialogCloseHandler()` — решение «удалить», не «реализовать».** У `DialogManager` каждый
диалог уже сам навешивает обработчик закрытия на единственную свою кнопку внутри `_buildDialog()` →
`_attachEvents()` — реализовать `attachCloseHandler(dialog, closeButton)` для произвольной внешней
кнопки означало бы придумывать новую функциональность без единого известного потребителя (YAGNI).
Шим убран из `prefill.frontend.js`; остальные восемь `@deprecated`-делегатов в том же классе рабочие
(проксируют в реальные методы модулей) и не тронуты — они не были частью этой находки.

Бандл `prefill.frontend.min.js` не пересобирался — по правилам проекта пересборка происходит на шаге
`/release-pack`, не после каждой правки исходников.

`php -l` чист по всем пяти изменённым PHP-файлам, все 21 файл `tests/*Test.php` зелёные без
изменений в самих тестах (ни один тест не трогал удалённые методы).

## Связанное

[issue-71](issue-71-dead-code-in-release-archive.md) — предыдущая уборка мёртвого кода перед релизом.
[issue-55](issue-55-guest-apply-delivery-ignores-order-id.md), [issue-83](issue-83-fillparams-payment-fields-never-set.md) —
откуда взялась часть остатков.
