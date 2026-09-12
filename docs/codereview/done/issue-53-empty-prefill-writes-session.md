# Issue 53 — Предзаполнение пишет пустые значения в сессию каждому посетителю

**Статус:** ✅ Закрыта — исправлено `stripEmptyLeaves()` в `shopPrefillPluginHelper`, вызывается в `preFillCheckoutParams()` перед проверкой `empty($final_params)`. Подтверждено живым тестом: гость без заказов не создаёт сессию (`use_storage: true`), контакт с адресом в профиле, но без заказов, получает регион из профиля, а не пустые поля; покупатель с реальным заказом и «Мои варианты доставки» продолжают работать без регрессий.
**Приоритет:** 🔴 Высокий (переклассифицирован: функциональные потери данных, не только производительность)
**Сложность фикса:** 🔧 Небольшой
**Файл:** `lib/classes/sessionstorage/shopPrefillPluginSessionStorageProvider.class.php` (`preFillCheckoutParams`, `prepareRegionSectionParams`, `prepareShippingSectionParams`, `preparePaymentSectionParams`)

## Проблема

`prepare*SectionParams()` пишут значения **безусловно**, даже когда `FillParams` пустой (посетитель без заказов):

```php
$final_params['order']['region']['country']  = $fill_params->getCountry();   // null
$final_params['order']['region']['region']   = $fill_params->getRegion();    // null
$final_params['order']['shipping']['type_id'] = $fill_params->getShippingTypeId(); // null
$final_params['order']['payment']['id']       = $fill_params->getPaymentId();      // null
```

`$final_params` получается непустым → срабатывает ветка записи:

```php
$merged = shopPrefillPluginHelper::deepMergeArrays($checkout_params, $final_params);
$this->setCheckoutParams($merged);
$this->saveSnapshot($merged);
```

Хук `frontend_head` работает на **всех** страницах магазина, а `prefill.on_entry = true` по умолчанию. Итог: каждый первый визит (включая ботов и краулеров) записывает в сессию два ключа — `shop/checkout` и `shop/prefill_snapshot` — забитых `null`-ами.

Показательно, что метод `shopPrefillPluginFillParams::hasDataForSection()` уже написан, но используется **только в debug-панели** — в самом предзаполнении он не вызывается.

## Почему запись стоит дорого

В `wa-system/storage/waSessionStorage.class.php`:

- `read()` сессию **не стартует** — просто читает `$_SESSION`;
- `write()` вызывает `open()` → `session_start()`;
- `init()` автостартует сессию **только** при уже существующей cookie (`isset($_COOKIE[session_name()])`).

Значит для посетителя без сессии именно наша запись создаёт PHP-сессию и `Set-Cookie: PHPSESSID`. Каждый бот = файл сессии на диске + cookie, ломающая кеширование на реверс-прокси. Без записи сессия бы не стартовала вообще.

## Последствия

Три подтверждённые цепочки. Все три бьют **ровно по тому посетителю, у которого данных для предзаполнения нет** — то есть по тому, кому плагин не должен мешать вообще.

### 1. `session_is_alive` подавляет восстановление из localStorage

`wa-apps/shop/lib/classes/checkout2/shopCheckoutViewHelper.class.php:439`:

```php
$result['session_is_alive'] = !empty($session_checkout['order']);
```

Шаблон `FrontendOrderForm.html` превращает это в `use_storage: {if $session_is_alive}false{else}true{/if}`.

Сценарий: гость заполнил чекаут, но **не оформил заказ** (значит `FillParams` пуст всегда) → PHP-сессия истекла, localStorage жив → он заходит на любую страницу → мы пишем `order` из семи `null` → `!empty($order)` = `true` → `use_storage: false` → JS **не восстанавливает** localStorage. Пользователь видит пустую форму, хотя браузер помнил его данные.

Связано с [session_is_alive не прокидывается в AJAX-рендере](../bugs/session-is-alive-storage-overwrite.md), но подход с другой стороны: там переменная не доезжает, здесь — доезжает с враньём.

### 2. Region-шаг отбрасывает адрес из профиля контакта

`wa-apps/shop/lib/classes/checkout2/shopCheckoutRegionStep.class.php:123`:

```php
$we_have_input = !empty($data['input']['region']) && is_array($data['input']['region']);
...
} elseif ($we_have_input) {
    $address = $address_from_input;      // «Это POST, берём ввод»
} else {
    $address = $address_from_contact;    // «Первая загрузка, берём адрес контакта»
}
```

Массив `['country' => null, 'region' => null, 'city' => null, 'zip' => null]` для `!empty()` — **непустой**. Наш блок из `null`-ов убеждает шаг, что пользователь что-то прислал, и адрес из профиля контакта (`address.shipping` → `address`) отбрасывается.

Бьёт по авторизованному покупателю **с адресом в профиле, но без заказов** — импортированные из CRM клиенты, оптовики, регистрация до первой покупки. Ядро бы предзаполнило ему регион из контакта, а наш плагин это ломает.

Порядок вызовов, из-за которого это работает именно так:

1. `shopCheckoutStep::processAll()` идёт по шагам, `checkout_before_auth` стреляет **до** `prepare()` шага `auth` (`shopCheckoutStep.class.php:243`);
2. `shopPrefillCheckoutState::__construct(array &$params)` держит `$params` по ссылке, `applyPrefillInput()` пишет в `$params['data']['input']`, а `$data` в `processAll` тоже связан по ссылке;
3. `region` — следующий шаг после `auth`, к его `prepare()` наши `null`-ы уже в `$data['input']['region']`.

### 3. Затирание полей, не входящих в `SECTION_KEY_FIELDS`

`canPrefillSection()` считает секцию свободной по **ключевым** полям, а пишем мы все. Расхождение:

| Секция | Ключевые поля | Пишем сверх того | Что затирается |
| --- | --- | --- | --- |
| `region` | `city`, `html` | `country`, `region`, `zip` | страна/регион/индекс при пустом городе |
| `shipping` | `type_id` | `variant_id` | выбранный вариант при пустом типе |

Практический сценарий: покупатель выбрал страну, отличную от дефолтной витрины, но города ещё не ввёл → следующий `calculate` пишет `country = null` → `getSelectedValues()` даёт `trim(null) = ''` → `empty($selected_values['country_id'])` → `getDefaultCountryID()` → **страна сбрасывается на дефолт**.

### 4. Производительность и чистота данных

- сессии создаются и раздуваются для всех посетителей, включая ботов;
- мусорные `null` в snapshot;
- шум в логах на уровне `info` (`Successfully prefilled checkout params` на каждой странице). Актуально только когда админ поднял уровень: по умолчанию уровень `warning`, `debug`/`info` — no-op.

## Решение

Отфильтровать пустые листья **на выходе сборки** в `preFillCheckoutParams()` — после шести секционных блоков, перед `if (!empty($final_params))`:

```php
// shopPrefillPluginHelper
public static function stripEmptyLeaves(array $data): array
{
    $result = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $value = self::stripEmptyLeaves($value);
            if ($value !== []) {
                $result[$key] = $value;
            }
        } elseif ($value !== null && $value !== '') {
            $result[$key] = $value;
        }
    }
    return $result;
}
```

```php
// preFillCheckoutParams(), перед проверкой empty()
$final_params = shopPrefillPluginHelper::stripEmptyLeaves($final_params);
```

Пустое = только `null` и `''`. **`0`, `'0'`, `false` сохраняем**: среди кастомных полей адреса бывает «этаж 0». По этой же причине нельзя переиспользовать `shopPrefillPluginSectionChecker::isValueFilled()` — он режет `0` и `'0'`.

### Почему это не костыль

Ни плагин, ни ядро нигде не отличают `null` от отсутствующего ключа:

- `shopPrefillPluginFillParamsProvider::getFillParamsByCheckoutParams()` читает сессию через `isset()` — для `null` это `false`;
- `shopPrefillPluginSectionChecker::isValueFilled()` считает `null` незаполненным;
- `shopPrefillCheckoutState` читает всё через `??`;
- в `wa-apps/shop/lib/classes/checkout*` нет ни одного `array_key_exists` — везде `??` / `empty()` / `ifset()`.

Фильтр не подчищает за билдерами, а приводит результат сборки к контракту, который весь остальной код уже предполагает. Поведенчески меняется ровно одно: `!empty($order)` перестаёт врать — а на этом стоят и `session_is_alive`, и `$we_have_input`, и наша собственная ветка записи.

## Анализ регрессий: почему логика плагина не ломается

Фильтр применяется **только внутри `preFillCheckoutParams()`**. `prepare*SectionParams()` и `applyDeliveryAddress()` не трогаются.

### Инвариант 1: `applyDeliveryAddress()` сохраняет очистку

`applyDeliveryAddress()` зовёт те же prepare-методы, но там `null` — рабочий **механизм очистки**: при переключении курьер → ПВЗ пустой `street` должен стереть старую улицу. Это подтверждается фронтендом: `js/modules/ParamsChoiceManager.js:88-90` после `apply-delivery` делает `location.reload()`, и форма рендерится из сессии как из единственного источника правды. Фильтр туда не заходит — семантика сохраняется полностью.

Отсюда же вывод, почему нельзя чинить в билдерах (вариант «писать только непустое» внутри `prepare*`): это сломает apply-delivery, и чинить пришлось бы флагом режима — делать prepare-методы двурежимными ради задачи, которая их не касается.

### Инвариант 2: секция из snapshot не может исчезнуть

`getSnapshotSection()` возвращает секцию только если `isSectionFilled($section_id, $snapshot)` = `true`, то есть хотя бы одно ключевое поле прошло `isValueFilled()` (а он режет `null`, `''`, `'0'`, `0`). Фильтр режет только `null` и `''` — строго меньше. Значит поле, из-за которого секция прошла проверку, всегда переживает фильтр, и восстановление из snapshot не ломается ни в одном случае.

### Инвариант 3: направление изменения — только «не затирать»

`deepMergeArrays($checkout_params, $final_params)` — merge, а не replace. Выброшенный из `$final_params` ключ означает, что значение из `$checkout_params` **сохранится** вместо перезаписи на `null`/`''`. Предзаполнение никогда ничего не очищает по замыслу (оно работает только по секциям, признанным пустыми), так что «не затирать» — это ровно то, чего мы хотим. Список того, что перестанет затираться, — таблица из последствия №3.

### Посекционная проверка

| Секция | Что может отфильтроваться | Потребитель | Разница |
| --- | --- | --- | --- |
| `auth` | `data.*` с пустыми значениями (`mode` и так условный) | `shopCheckoutAuthStep`, чтение через `??` | нет |
| `region` | `country`, `region`, `city`, `zip` | `getSelectedValues()`: `((array)$address) + [...]` заполняет только отсутствующие ключи, дальше `trim()` + `empty()` | нет: `null` и отсутствие одинаково дают дефолт |
| `shipping` | `type_id`, `variant_id` | шаг выбирает вариант по значению | нет: «ничего не выбрано» в обоих случаях |
| `details` | `street`; `zip` и кастомные поля уже пишутся условно | `shopCheckoutDetailsStep` | нет |
| `payment` | `id`, `custom.*` | шаг выбирает способ по id | нет |
| `confirm` | `comment` (пустая строка) | textarea | нет: предзаполнять пустой комментарий бессмысленно |

### Проверка остальных путей вызова

| Путь | Эффект |
| --- | --- |
| `handleFrontendHead()` | для посетителя без данных запись не происходит → сессия не стартует. Цель фикса |
| `handleCheckoutBeforeAuth()` | `$filled_order` чаще пустой → `applyPrefillInput()` не вызывается. Он и сам делает `if (empty($filled_order)) return;` — поведение идентично |
| `applyDeliveryAddress()` | не затронут |
| `resetAndRefill()` | после очистки ключей повторный префилл ничего не пишет, если данных нет. Корректно: раньше кнопка «Reset & Refill» возвращала в сессию мусор |
| `FrontendForcePrefill`, `FrontendFillCheckoutParams` | тот же `preFillCheckoutParams()`, отдельной логики нет |
| debug-панель | `logDebugAfterPrefill()` покажет пустую сессию там, где данных нет. Это правда, а не поломка |
| флаг `prefilled` | ставится после фильтра, как и раньше — защита «один раз за запрос» не меняется |

### Что НЕ меняется намеренно

- `prepare*SectionParams()` — ни одной правки;
- `deepMergeArrays()` — общий хелпер, фильтрация там сломала бы очистку глобально;
- `setCheckoutParams()` / `saveSnapshot()` — фильтр в сеттере был бы неожиданным побочным эффектом и задел бы apply-delivery.

## Отклонённые рекомендации из первой редакции документа

**«Ранний выход, если у `FillParams` нет данных ни по одной секции»** — отклонено. Безопасное условие тройное: `нет данных && $snapshot === null && empty($checkout_params)`. Проще нельзя: выход только по пустому `FillParams` убьёт восстановление из snapshot (гость заполнил форму руками, заказов нет), а выход при непустом `$checkout_params` убьёт ветку `else`, обновляющую snapshot. При этом экономит выход шесть вызовов `canPrefillSection()` и debug-логи, которые в проде выключены. Польза нулевая, риск ненулевой.

**«Использовать `hasDataForSection($section_id)` рядом с `canPrefillSection()`»** — отклонено. Метод ничего не знает про snapshot: `canPrefill && hasData` отключит восстановление секции из snapshot. Плюс он рассинхронизирован с тем, что пишется: проверяет `country_name` / `region_name`, а пишутся коды `country` / `region`. Ровно поэтому метод и завис в debug-панели — это не недосмотр, а следствие его непригодности для боевого пути.

**«Не предзаполнять вне чекаута»** — отклонено. `waViewController::executeAction()` рендерит экшен, и только потом `display()` → layout → `frontend_head` (`wa-apps/shop/lib/layouts/shopFrontend.layout.php:39`). На странице `/order/` наш `frontend_head` физически позже, чем `formVars()` прочитал сессию. Чекаут работает благодаря `checkout_before_auth` внутри `processAll()`, а `on_entry` нужен другим потребителям сессии: `shopFrontendShipping.controller.php:118` (расчёт доставки на карточке товара) и `shopDiscounts.class.php:350`.

## Бонусы рядом (опционально, тем же коммитом)

**Лишняя запись snapshot.** Ветка `else` зовёт `saveSnapshot($checkout_params)` на каждой странице у любого посетителя с непустым чекаутом. Условие `if ($snapshot != $checkout_params)` убирает это даром.

**Гарантированно пустой запрос к БД.** В `shopPrefillPluginFillParamsProvider::getFillParamsForGuest()` хеш для нового посетителя **только что сгенерирован** — заказа с ним в БД быть не может по построению. `getGuestHash()` (read-only, уже есть в `shopPrefillPluginGuestHashStorage`) вернёт `null` → можно выйти сразу, без `SELECT` по `shop_order_params`. Снимает запрос к БД на каждый визит каждого бота.

## План проверки

1. Инкогнито, гость без заказов → главная → любая страница. В логе (уровень `info`) не должно быть `Successfully prefilled checkout params`; в debug-панели `shop/checkout` пуст.
2. Тот же гость → `/order/` → в инлайновом скрипте формы `use_storage: true`.
3. Авторизованный контакт с адресом в профиле, но **без заказов** → `/order/` → регион подставлен из профиля.
4. Покупатель с заказом → префилл работает как раньше (регион, доставка, оплата, комментарий).
5. «Мои варианты доставки»: курьер → ПВЗ → улица очистилась, чужой адрес не залип.
6. Снапшот: заполнить форму руками, очистить город → город восстанавливается из snapshot.

## Смежные находки

Найдены по ходу разбора, к фиксу этой задачи не относятся — вынесены отдельно:

- [issue-59 — ключ `html` считается данными: предзаполнение и snapshot-восстановление молча отключаются](issue-59-html-key-marks-section-filled.md)
- [issue-60 — shipping-билдер пишет в чужую секцию, восстановление `details` из снапшота её затирает](issue-60-cross-section-write-details-custom.md)
- [issue-61 — дубликат класса в `lib/classes/fillparams/`: мина в автозагрузке, уезжает в релиз](issue-61-duplicate-class-file-autoload.md)
- [issue-62 — мёртвый публичный эндпоинт `fillCheckoutParams` без проверки доступа](issue-62-dead-unguarded-fill-checkout-endpoint.md)
