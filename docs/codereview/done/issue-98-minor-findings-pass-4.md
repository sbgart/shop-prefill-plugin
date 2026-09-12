# Issue 98 — Мелкие находки полного ревью (четвёртый проход)

**Статус:** 🔍 Открыта 09.09.2026
**Приоритет:** 🟢 Мелочь и гигиена
**Сложность фикса:** 🔧 Небольшой (каждый пункт отдельно)
**Файлы:** см. по пунктам

Шесть независимых мелочей полного ревью 09.09.2026. Каждая чинится отдельно, ни одна не блокирует
релиз.

---

## 1. Диагностика `checkoutBeforeAuth` считается при выключенной панели

**Файл:** `lib/classes/hooks/shopPrefillPluginCheckoutHooks.class.php` (`handleCheckoutBeforeAuth()`)

`shopPrefillPluginDebug::recordEvent()` первым делом выходит, если панель выключена:

```php
public static function recordEvent(string $stage, string $label, array $data = []): void
{
    if (!self::$enabled || count(self::$events) >= self::MAX_EVENTS) {
        return;
    }
```

Но аргументы вычисляются до вызова, а собирает их весь пролог метода:

```php
$source_before  = $this->session_storage->getAppliedSource();
$storage_before = $this->session_storage->getCheckoutParams();
$checker = $this->session_storage->getSectionChecker();
$section_decisions = [];
foreach (['auth', 'region', 'shipping', 'details', 'payment', 'confirm'] as $section_id) {
    $section_decisions[$section_id] = $checker->inspectPrefillSection($section_id, $storage_before);
}
…
'session_changed_paths' => $this->findChangedPaths($storage_before, $this->session_storage->getCheckoutParams()),
'input_changed_paths'   => $this->listLeafPaths($filled_order),
```

Из всего этого рабочему коду нужны только `$source_key` и `$filled_order`. Остальное — шесть вызовов
`inspectPrefillSection()`, второе чтение сессии и два рекурсивных обхода дерева параметров
(`findChangedPaths()` делает `array_unique(array_merge(array_keys(…)))` и `array_merge` на каждом
уровне) — существует исключительно ради debug-события и выполняется на **каждом** `/order/calculate/`
в проде, где панель по умолчанию выключена (`storefront.settings.php`, `debug_panel => false`).

Честно о масштабе: дерево `shop/checkout` — десятки листьев, в абсолютных числах это микросекунды,
замером на слабом хосте это не поймать. Находка не про измеримую просадку, а про то, что соседний
метод того же класса делает правильно, а этот — нет:

```php
// renderSectionErrorsAndDebug()
if ($this->is_debug_panel) {
    shopPrefillPluginDebug::recordEvent('render', $hook_name, [ … ]);
}
```

**Что сделать:** обернуть пролог тем же `if ($this->is_debug_panel)`, оставив снаружи `$source_key` и
вызов `preFillCheckoutParamsFromSource()`. Либо, если хочется сохранить один стиль, — принимать в
`recordEvent()` `callable` и вызывать его после проверки уровня.

---

## 2. Нет индекса по `storefront_code` — каждое чтение настроек сканирует таблицу целиком

**Файлы:** `lib/config/db.php`, `lib/models/shopPrefillPluginSettings.model.php`

Все четыре запроса модели фильтруют по `storefront_code`:

```php
$sql = "SELECT * FROM {$this->table} WHERE `storefront_code` = s:storefront_code";
```

А в схеме объявлен один ключ:

```php
':keys' => array(
    'PRIMARY' => 'id',
),
```

Проверено на стенде (`information_schema.statistics`): у `shop_prefill_settings` есть только
`PRIMARY(id)`. Значит каждое чтение — full scan. За запрос их два: глобальные настройки (`*`) и
настройки эффективной витрины; дальше работает статический кэш модели, так что именно два, не больше.

Масштаб на стенде: 242 строки (92 + 76 + 70 + 4). Скан такого объёма ничего не стоит. Проблема
появляется линейно с числом витрин — у магазина с двумя десятками точек входа таблица уходит за
пару тысяч строк, и они сканируются дважды на каждый запрос чекаута.

**Что сделать:** добавить в `db.php` `'storefront_code' => 'storefront_code'` в `:keys` и миграцию в
`lib/updates/` (идемпотентную — проверять наличие индекса перед `ALTER`). Заодно она покроет
`setBulk()` и `deleteOrphanedGroups()`, читающие ту же колонку.

---

## 3. `rememberMe()` пишет `auth_token` без `cookie_domain` авторизационного конфига

**Файл:** `lib/classes/user/shopPrefillPluginUserProvider.class.php` (`rememberMe()`)

```php
waSystem::getInstance()->getResponse()->setCookie(
    'auth_token', $token, time() + $ttl, null, '', waRequest::isHttps(), true
);
```

Пятый аргумент — домен, и он пуст всегда. Ядро в тех же местах берёт его из конфига авторизации:

```php
// wa-system/auth/waAuth.class.php:741 (_remember)
$cookie_domain = ifset($this->options['cookie_domain'], '');
$response->setCookie('auth_token', $this->getToken($user_info), time() + 2592000, null, $cookie_domain, false, true);
```

**Следствие:** на установке, где `cookie_domain` задан (общая авторизация между поддоменами — а на
этом стенде как раз три точки входа, включая `shop-1.wa-dev.loc`), выданный плагином токен работает
только на своём хосте. Ядровый — на всех. Покупатель, которого плагин авторизовал после оформления
заказа, на соседнем поддомене окажется гостем, хотя штатный вход его бы туда пронёс.

Утечки нет: логаут ядра чистит и хост-куку тоже (`waAuth.class.php:946-951` — при заданном
`$cookie_domain` шлётся второй `setCookie` уже без домена).

**Что сделать:** брать домен из того же источника, что и ядро — `waDomainAuthConfig::factory()`
уже используется рядом в `isDomainRememberMeEnabled()`; если у него нет геттера домена, прочитать
`getAuth()->getOptions()['cookie_domain']`.

---

## 4. Эндпоинт `prefill/logs` без проверки метода и CSRF, `message` без типа

**Файл:** `lib/actions/frontend/shopPrefillPluginFrontendLogs.controller.php`

Три соседних debug-эндпоинта (`ClearStorage`, `ForcePrefill`, `ResetAndRefill`) проверяют одинаково:

```php
return waRequest::method() === 'post'
    && shopPrefillPlugin::getInstance()->isDebug()
    && wa()->getUser()->isAdmin('shop')
    && $sent !== ''
    && hash_equals($cookie, $sent);
```

`Logs` — только два условия из пяти:

```php
if (! shopPrefillPlugin::getInstance()->isDebug() || ! wa()->getUser()->isAdmin('shop')) {
```

Плюс параметр читается без типа и подставляется в строку:

```php
$message = waRequest::post('message', null);
…
shopPrefillPluginLog::error("[Frontend] {$message}");
```

`message[]=x` даст «Array to string conversion», а перевод строки внутри `message` — поддельную
запись в логе плагина.

Эксплуатации отсюда нет: нужен администратор магазина при включённом глобальном debug, то есть тот,
кто и так может писать в лог чем угодно. Находка про единообразие: четыре эндпоинта одного назначения
защищены по-разному, и разница ничем не объяснена.

**Что сделать:** вынести общий `isAllowed()` в базовый класс (он трижды скопирован дословно) и
подключить к нему `Logs`. `message` читать как `waRequest::TYPE_STRING` и резать переводы строк.

---

## 5. Диалоги фронтенда собираются в JS строками

**Файлы:** `js/modules/DialogManager.js` (`_buildDialog()`:146, `_renderContent()`:213, 223),
`js/modules/ZenModeToggle.js` (`showNoticeDialog()`), `js/modules/ParamsChoiceManager.js`
(`showDeliveryUnavailableDialog()`)

Прямое нарушение правила проекта из `CLAUDE.md` («Не собирать HTML в JS. <…> Диалоги, модалки,
элементы списков и прочие динамические блоки объявляй в шаблоне внутри `<template id="...">`»):

```js
dialog.innerHTML = `
    <div class="prefill-dialog__header">
        <h3 class="prefill-dialog__title"></h3>
        …`;
```

```js
const content = `
    <div class="prefill-warning">
        <p class="prefill-warning__text">${message}</p>
        <button class="button prefill-warning__btn js-close-dialog">${okText}</button>
    </div>`;
```

XSS здесь нет: во все интерполяции идут строки локализации из `js_params.messages`, а серверный HTML
(`params-choice`) вставляется в контент намеренно. Проблема — та, ради которой правило и написано:
разметку диалогов нельзя поправить в шаблоне, а `SaveScopeDialog.html` в админке живёт по правилам.

В [TODO.md](../TODO.md) записан только `prefill.settings.js` («много HTML собирается в JS, вынести в
Smarty-шаблоны») — фронтенд в бэклоге не значится вовсе.

**Что сделать:** добавить строку в бэклог TODO рядом с пунктом про `prefill.setting.js`, объединив их
в одну задачу «вынести разметку диалогов в `<template>`». Отдельным issue это не тянет — работа
одинаковая, эталон (`SaveScopeDialog.html` + `prefill.save-scope.js`) уже есть.

---

## 6. `collectUniqueDeliveryOrderIds()` перебирает историю заказов без потолка

**Файл:** `lib/classes/fillparams/shopPrefillPluginFillParamsProvider.class.php`

```php
while (count($unique_orders_ids) < $limit) {
    $orders_ids = $this->order_provider->getUserOrdersId($contact_id, $page_size, $offset);
    if (empty($orders_ids)) { break; }
    …
    if (count($orders_ids) < $page_size) { break; }
    $offset    += $page_size;
    $page_size = min($page_size * 2, self::HISTORY_PAGE_MAX);
}
```

Цикл останавливается по набору `$limit` различных вариантов или по концу истории. Покупатель, у
которого вариант всего один (оптовик, всегда один адрес и один способ), никогда не наберёт `$limit`
(по умолчанию 5) — значит история читается **до конца**: 50 + 100 + 200 + 400 + 400 + … заказов,
по два запроса на страницу, с созданием `shopPrefillPluginFillParams` на каждый заказ ради сравнения
`isSameDeliveryOption()`.

Для 500 заказов это ~8 запросов и ~500 объектов; для 5000 — ~26 запросов и 5000 объектов, на слабом
хосте уже заметно. Срабатывает только по явному действию — открытию диалога «Мои варианты», не на
каждой странице.

Заметно мягче, чем было до [issue-68](issue-68-params-choice-collection-n-plus-1.md) (там N+1 на
заказ), но потолка так и нет.

**Что сделать:** добавить второй ограничитель — максимум просмотренных заказов (скажем,
`HISTORY_SCAN_MAX = 500`) и выходить по нему же. Пять вариантов из последних пятисот заказов — не
хуже пяти из всех, а верхняя граница работы становится константой.

---

## Связанное

[issue-74](issue-74-minor-findings-pass-2.md), [issue-80](issue-80-frontend-minor-findings.md),
[issue-92](issue-92-minor-findings-pass-3.md) — предыдущие пачки мелочи.
[issue-81](issue-81-log-level-never-set-in-plugin-endpoints.md) — соседний разбор диагностики
в собственных эндпоинтах (п. 4).
[issue-68](issue-68-params-choice-collection-n-plus-1.md) — предыстория п. 6.
