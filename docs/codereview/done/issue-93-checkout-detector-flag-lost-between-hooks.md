# Issue 93 — Признак «сработал checkout-хук» не доживает до `frontend_head`: детектор чекаута работает только по маршруту

**Статус:** ✅ Исправлена 09.09.2026, в день находки — флаг переведён в `private static bool $checkout_hook_fired` детектора, добавлен тест «пометил один экземпляр, спрашивают у другого», формулировка A1 уточнена
**Приоритет:** 🟠 Важно до продажи
**Сложность фикса:** 🔧 Небольшой
**Файлы:** `lib/shopPrefill.plugin.php` (`$checkout_page_detector`, строка 53; `getCheckoutPageDetector()`, `enterCheckoutHooks()`), `lib/classes/checkout/shopPrefillPluginCheckoutPageDetector.class.php` (`markCheckoutHookFired()`), `tests/CheckoutPageDetectorTest.php`

## Как это работает сейчас

Детектор отвечает на вопрос «рендерит ли этот запрос форму заказа» по двум признакам — сработавший
checkout-хук и маршрут `frontend/order`. Первый признак объявлен основным, второй — подстраховкой:

```php
// shopPrefillPluginCheckoutPageDetector.class.php
 * 1. Сработал любой checkout-хук плагина. Признак точный и не зависит от шаблонов темы
 *    (правило B3) <…> Ловит и нестандартный случай «форма вставлена
 *    в произвольную страницу витрины».
```

Флаг живёт в поле экземпляра детектора, а сам детектор — в поле экземпляра плагина:

```php
// shopPrefill.plugin.php:53
private ?shopPrefillPluginCheckoutPageDetector $checkout_page_detector = null;

private function enterCheckoutHooks(): shopPrefillPluginCheckoutHooks
{
    $this->getCheckoutPageDetector()->markCheckoutHookFired();
    return $this->getCheckoutHooks();
}
```

Ядро создаёт **новый объект плагина на каждое событие** — не на запрос:

```php
// wa-system/event/waEvent.class.php:239 (внутри runPlugins())
$plugin = new $class($plugin_info);

foreach ($methods as $method) {
    $plugin_result = $plugin->$method($params, $this->name);
```

`checkout_before_auth` и `frontend_head` — два разных события, поднимаемых из разных мест ядра:

- `wa-apps/shop/lib/classes/checkout2/shopCheckoutStep.class.php:243` — `wa('shop')->event('checkout_before_'.$step_id, …)`
- `wa-apps/shop/lib/layouts/shopFrontend.layout.php:39` — `wa()->event('frontend_head')`

Значит `markCheckoutHookFired()` ставит флаг объекту №1, а `handleFrontendHead()` спрашивает
`isCheckoutPage()` у детектора объекта №N, где флаг равен `false` с рождения. Это тот же корень, что
у [issue-73](issue-73-stale-plugin-singleton.md): поле экземпляра живёт один хук, а не запрос.
Статические кэши плагина (`$effective_storefront`, `$active`) от этого защищены, детектор — нет.

Тест `tests/CheckoutPageDetectorTest.php` проблему не ловит, потому что проверяет класс в изоляции —
на одном объекте:

```php
$embedded = makeDetector(['frontend', 'page']);
$embedded->markCheckoutHookFired();
assertSameValue(true, $embedded->isCheckoutPage(), 'форма вставлена в произвольную страницу — хук это доказал');
```

В проде этот сценарий недостижим: пометивший и спрашивающий — разные объекты.

## Что из этого следует

Фактически работает **только признак 2** — маршрут `frontend/order`. Пока чекаут живёт на штатном
маршруте, снаружи ничего не заметно: ассеты подключаются, всё как задумано. Поэтому находка не
блокер.

Проявится на теме, которая рендерит форму заказа вне маршрута `frontend/order` (форма, вставленная в
произвольную страницу витрины, — ровно тот случай, ради которого признак 1 и заводили). Там:

1. `isCheckoutPage()` → `false` → `initializeFrontendAssets()` выходит по первому же условию, ни
   `frontend.min.js`, ни `zenmode.css`, ни JS-инициализатор не подключаются;
2. а checkout-хуки при этом отработали и вывели свои блоки — включая **скрывающий CSS** группы,
   который `renderCollapseBlock()` эмитит инлайном, независимо от ассетов
   (`generateGroupStyles()`, `<style id="prefill-zen-styles-…">`);
3. кнопка «Изменить» в разметке есть, но `ZenModeToggle` не загружен — клик ничего не делает.

Итог — чекаут без выхода: содержимое секций скрыто, развернуть его нечем. Это ровно тот отказ,
который закрывала [issue-75](issue-75-zen-collapse-without-toggle-button.md), и прямое нарушение Z3.

Побочно: правило A1 в [RULES.md](../concept/RULES.md) описывает механизм, которого нет — «сработавший
checkout-хук (ядро зовёт их до макета, поэтому `frontend_head` успевает)». Хук действительно
срабатывает раньше, но его отметка до `frontend_head` не доезжает по другой причине — объект другой.

## Что сделано

1. `private bool $checkout_hook_fired` → `private static bool $checkout_hook_fired` в самом детекторе,
   с мотивировкой в комментарии — той же, что у `shopPrefillPluginFillParamsProvider::$fill_params_memo`.
   `$checkout_page_detector` в плагине остался полем экземпляра: новый детектор видит отметку прошлого.
   Область действия статики — запрос: за один запрос рендерится ровно одна страница, поэтому
   сработавший хук всегда относится к той же странице, что и `frontend_head`; на `/order/calculate/`
   `frontend_head` не поднимается вовсе, так что лишних подключений отметка не создаёт.
2. В `tests/CheckoutPageDetectorTest.php` добавлен случай «отметку поставил один экземпляр детектора,
   спрашивают у другого» и обратный к нему — «сброшенная отметка не оставляет следа» (щит от протечки
   статики на каталог, то есть от возврата issue-64). Между блоками теста статика обнуляется
   `forgetCheckoutHookForTests()` — метод существует только ради тестов, в бою статика умирает
   вместе с процессом.
3. A1 в [RULES.md](../concept/RULES.md) переписан: признак переживает границу события за счёт статики.
4. Живой проверки не требуется: на теме `default` чекаут на штатном маршруте, там работал и работает
   признак 2. Все 19 автотестов зелёные.

## Исходная рекомендация

1. Перевести флаг в `static`-поле — как это уже сделано для `shopPrefillPluginFillParamsProvider::$fill_params_memo`
   и `shopPrefillPluginOrderProvider::$order_rows`, с той же мотивировкой в комментарии. Минимальная
   правка — `private bool $checkout_hook_fired` → `private static bool $checkout_hook_fired` в самом
   детекторе (тогда и `$checkout_page_detector` в плагине может остаться полем экземпляра: новый
   детектор увидит отметку прошлого).
2. В тесте добавить случай «отметку поставил один экземпляр детектора, спрашивают у другого» —
   иначе регресс вернётся при следующем рефакторинге.
3. Уточнить A1 в RULES.md: сказать, что признак переживает границу события за счёт статики.
4. Проверить вживую нельзя на теме `default` (у неё чекаут на штатном маршруте) — достаточно теста
   из п.2 плюс проверка, что на `/order/` ассеты по-прежнему подключаются.

## Связанное

[issue-73](issue-73-stale-plugin-singleton.md) — тот же корень: объект плагина пересоздаётся на
каждый хук, per-instance кэши значат не то, что написано в комментариях.
[issue-64](issue-64-assets-loaded-on-every-page.md) — ради чего детектор появился.
[issue-75](issue-75-zen-collapse-without-toggle-button.md) — отказ, к которому приводит.
Правила A1 и Z3 в [RULES.md](../concept/RULES.md).
