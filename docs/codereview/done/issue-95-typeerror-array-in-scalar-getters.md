# Issue 95 — Массив вместо строки в POST роняет чекаут 500-й: типизированные геттеры `CheckoutState` бросают `TypeError`, а `waEvent` его не ловит

**Статус:** ✅ Исправлена 09.09.2026, в день находки — приведение типов на границе `CheckoutState` (`scalarString()` / `arrayValue()`), `catch (Throwable)` во всех точках входа плагина (`guardHook()`), тест `CheckoutStateTypeCoercionTest` (59 проверок), правило записано следствием к B2a. Живое воспроизведение до и после — ниже, в разделе «Проверка на стенде»
**Приоритет:** 🟠 Важно до продажи
**Сложность фикса:** 🔧 Небольшой
**Файлы:** `lib/classes/checkout/shopPrefillCheckoutState.class.php` (все геттеры, читающие `$params`), `lib/shopPrefill.plugin.php` (`guardHook()` и восемь точек входа), `lib/classes/hooks/shopPrefillPluginCheckoutHooks.class.php` (`buildZenModeGroupBlock()`, `renderConsentCheckbox()`, `applyEchoToInput()`), `tests/CheckoutStateTypeCoercionTest.php`

## Как это работает сейчас

Геттеры объявлены с возвратом `string`, но читают значения, часть которых приезжает прямо из POST
покупателя, без приведения типа:

```php
public function getFirstName(): string
{
    return $this->params['vars']['auth']['fields']['firstname']['value']
        ?? $this->params['data']['input']['auth']['data']['firstname']
        ?? '';
}

private function findAddressField(string $field): string
{
    return $this->params['data']['shipping']['address'][$field]
        ?? $this->params['data']['input']['details']['shipping_address'][$field]
        ?? $this->params['data']['input']['region'][$field]
        ?? $this->params['vars']['region']['selected_values'][$field]
        ?? '';
}
```

`vars.auth.fields[*].value` ядро само собирает из POST и **не приводит к строке** для типов
`Name`/`String` — `$base_value` из контакта кастуется (`shopCheckoutAuthStep.class.php:116`,
`(string)$base_value`), а `$value = ifset($input_values, $field_id, $base_value)` из POST проходит
`formatContactFields()` насквозь (`shopCheckoutConfig.class.php:823, 830`). `data.input.*` — это
вообще сырой `order`-массив запроса.

Проверено на самом классе (09.09.2026):

```
$params = ["vars" => ["auth" => ["fields" => ["firstname" => ["value" => ["injected"]]]]]];
→ TypeError: Return value of shopPrefillCheckoutState::getFirstName() must be of the type string, array returned

$params = ["data" => ["input" => ["details" => ["shipping_address" => ["city" => ["x"]]]]]];
→ TypeError: Return value of shopPrefillCheckoutState::findAddressField() must be of the type string, array returned
```

## Зеркальный случай: скаляр под массивным ключом

Симметричная подстановка так же реальна и в исходной постановке пропущена. `getCustomPaymentFields()`
объявляет `: array`, а читает `data.input.payment.custom` — то есть тот же сырой POST:

```php
public function getCustomPaymentFields(): array
{
    return $this->params['data']['input']['payment']['custom']
        ?? $this->params['data']['payment']['custom']
        ?? [];
}
```

`payment[custom]=x` (строка вместо массива) даёт тот же непойманный `TypeError`. Проверено на
версии класса до фикса:

```
→ TypeError: Return value of shopPrefillCheckoutState::getCustomPaymentFields() must be of the type array, string returned
```

Рядом — `getCustomContactFields()` и `getCustomAddressFields()`, делающие `foreach` по
`data.input.auth.data` и `data.input.details.shipping_address`: на PHP 7.4 скаляр там даёт warning,
на PHP 8 — фатальную ошибку. И `applyPrefillInput()` / `applyEchoToInput()`, передающие
`data.input` (и `data.input.<секция>`) в `deepMergeArrays(array $base, array $override)`: скаляр под
`input` — снова `TypeError`. Поэтому чинить надо оба направления сразу, одним проходом по классу.

Перехватить это некому. `buildZenModeGroupBlock()` ловит `Exception`, а `TypeError` — это `Error`:

```php
} catch (Exception $e) {
    shopPrefillPluginLog::error('Zen Mode error in ' . $log_context, …);
    return '';
}
```

Ядро тоже ловит только `Exception`:

```php
// wa-system/event/waEvent.class.php:268
} catch (Exception $e) {
    $this->debugLog('Event handling error in '.$class.":\n".$e->getMessage()…);
}
```

Плагин про это знает — в `orderActionCreate()` и `controllerBefore()` стоит `catch (Throwable)` ровно
с этим обоснованием («waEvent::runPlugins() ловит только Exception: любой Error (TypeError, вызов на
null) ушёл бы наверх»). В render-хуках такой защиты нет.

## Что из этого следует

Путь достижения — самый горячий из возможных: `hasCustomerIdentityData()` зовёт `getFirstName()`,
`getPhone()`, `getEmail()`; её зовёт `isGroupMinimumFilled('customer')` из `shouldCollapseGroup()`,
то есть **на каждом рендере группы «Покупатель»** при включённом Zen (умолчание — включён).
Адресные геттеры так же безусловно читаются в `extractSummaryData()` для свёрнутой группы `delivery`.

Сценарий отказа: любой запрос к `/order/` или `/order/calculate/`, где под скалярным полем приезжает
массив, — `auth[data][firstname][]=x`, `details[shipping_address][city][]=x`, `region[region][]=x`, —
даёт непойманный `TypeError` и 500 вместо страницы оформления заказа.

Два реалистичных источника такого POST:

1. **Сторонний плагин или тема**, отрисовавшая в чекауте многозначное поле в пространстве имён
   `auth[data][…]`, `details[shipping_address][…]` или `region[…]` (мультиселект, чекбокс-группа,
   повторяющееся поле адреса). Ядро такие поля само не рендерит — `formatContactFields()` пропускает
   `waContactChecklistField` через `continue 3` — но чужой код о нашем контракте не знает. Плагин
   продаётся на произвольные магазины, где стоят произвольные соседи.
2. Кустарный запрос: покупатель ломает собственный чекаут, ущерб только себе. Как вектор атаки
   ценности не имеет.

Опаснее всего именно первый: магазин ставит два плагина, чекаут падает 500-й, и виноватым выглядит
prefill — справедливо, потому что ядро в этой ситуации отделывается «Array to string conversion»
и продолжает работать.

Это прямое нарушение B2a: при неопределённости плагин обязан отступить и отдать стоковый чекаут, а
не уронить магазин.

## Рекомендация (выполнена)

1. Ввести один приватный хелпер приведения (`scalarString($value): string` — `is_scalar($value) ? (string)$value : ''`)
   и пропускать через него все `: string`-геттеры, читающие `vars.*`/`data.input.*`. Массив трактуем
   как «значения нет» — это и есть отступление к стоковому поведению.
2. Отдельно — `getServiceAgreement()`, `getShippingVariantId()`, `getPaymentId()`: там уже стоит
   явное приведение или сравнение, но стоит пройти по списку целиком, а не чинить три места.
3. Обернуть render-хуки в `catch (Throwable)` — как это сделано в `orderActionCreate()`. Даже с
   починенными геттерами это дешёвая страховка: плагин не должен уметь ронять оформление заказа.
   Место — `buildZenModeGroupBlock()` и `renderConsentCheckbox()` (заменить `Exception` на
   `Throwable`), плюс точки входа `checkoutRender*` в `shopPrefill.plugin.php`.
4. Тест: `tests/CheckoutStateScalarCoercionTest.php` — массив под `firstname`, `city`, `region`,
   `street`; ждём `''`, а не исключение.

## Что сделано

1. **Приведение на границе класса.** Два приватных статических хелпера — `scalarString($value): string`
   (`is_scalar() ? (string) : ''`) и зеркальный `arrayValue($value): array` — и через них пропущены
   **все** геттеры, читающие `$params`: контакт, адрес, тариф доставки, оплата, служебные поля ответа
   шага (`errors`, `error_step_id`, `delayed_errors`), `getData()`. Заодно ушли россыпью стоявшие
   `(string)`-касты, которые на массиве давали «Array to string conversion» вместо значения.
   Нескалярное значение трактуется как «значения нет» — отступление к стоковому чекауту (B2a).
   Цепочки `??` намеренно сохранены как есть: приведение навешено на результат цепочки, приоритет
   источников не менялся — пустая строка в старшем источнике по-прежнему перебивает младший.
2. **Мутация под защитой.** `applyPrefillInput()` проверяет `is_array($params['data']['input'])`
   вместо `isset()`, `applyEchoToInput()` — тип секции перед `deepMergeArrays()`.
3. **`catch (Throwable)` во всех точках входа.** В `shopPrefill.plugin.php` добавлен приватный
   `guardHook(string $hook, callable $handler, ?string $fallback)`; через него проходят шесть
   `checkoutRender*`, `checkoutBeforeAuth` и `frontendHead` — раньше голые, хотя `controllerBefore()`
   и `orderActionCreate()` уже были прикрыты ровно с этим обоснованием. Внутренние `catch (Exception)`
   в `buildZenModeGroupBlock()` и `renderConsentCheckbox()` подняты до `Throwable`. Одного лишь
   внутреннего перехвата недостаточно: `renderSectionErrorsAndDebug()`, `renderDeliveryUnavailableScript()`
   и сам `new shopPrefillCheckoutState($params)` лежат вне его try.
4. **Тест** `tests/CheckoutStateTypeCoercionTest.php` — 59 проверок: массив под каждым скалярным
   ключом, скаляр под каждым массивным, служебные поля, мутация, happy path (валидные данные должны
   проходить насквозь, а `int` — приводиться к строке, а не отбрасываться).
5. **Правило** записано следствием к B2a в [RULES.md](../concept/RULES.md): типы в `$params` ядром
   не гарантированы, приведение обязательно на границе `CheckoutState`.

## Проверка на стенде (09.09.2026)

Живой чекаут `wa-dev.loc`, гость через curl с пустой банкой кук: `GET /` → `POST /cart/add/` →
`POST /order/calculate/` с валидными телефоном и email плюс `auth[data][firstname][]=x`. Валидные
контактные поля здесь обязательны: без них группа `customer` разворачивается раньше — на
`hasGroupErrors()`, — и до геттеров дело не доходит. Отдельная проба подтвердила, что массив
доезжает до хука нетронутым: `vars.auth.fields.firstname.value` и `data.input.auth.data.firstname`
оба приходят как `array`, а решение группы — `collapsed: true, reason: minimum_filled`, то есть
`hasCustomerIdentityData()` и `getFirstName()` вызываются на этом запросе.

| Состояние кода | Ответ `/order/calculate/` |
|---|---|
| **До фикса** (все три файла из HEAD) | **`Fatal error: Uncaught TypeError: Return value of shopPrefillCheckoutState::getFirstName() must be of the type string, array returned`** — вместо JSON приходит 23 КБ дампа ошибки против штатных 143 КБ. Оформление заказа не работает: браузер ждёт JSON |
| Только слой 2 (`Throwable`-гарды, класс из HEAD) | Валидный JSON, 200. Свёрнутая карточка `customer` пропала — плагин деградировал до стокового вида; в `wa-log/prefill.plugin.error.log` запись `[ERROR] Zen Mode error in checkoutRenderAuth` с текстом `TypeError` |
| **После фикса** (оба слоя) | Валидный JSON, карточка `customer` свёрнута штатно (`prefill-zen-styles-customer` в разметке), в error-логе пусто |

Ядро на тех же данных отделывается `Notice: Array to string conversion` в `auth.html` и продолжает
работать — то есть 500 приносил именно плагин, ровно как предполагалось при находке.

Дополнительно проверено, что фикс не сломал обычную работу: девять вариантов ядовитого POST
(`auth[data][firstname][]`, `phone`/`email` массивами, `details[shipping_address][city|street|building][]`,
`region[region][]`, `payment[custom]=x`, `payment=x`, секции целиком скалярами) — все 200 с валидным
JSON; полная отрисовка `/order/` — 200; все 20 автотестов плагина зелёные.

Оговорка о коде ответа: на стенде включён xdebug с `display_errors`, поэтому фатальная ошибка
приезжает телом с кодом 200. На боевом магазине с выключенным `display_errors` тот же путь даёт
пустой ответ или 500 — для покупателя разницы нет, оформление заказа не работает в обоих случаях.

## Связанное

[issue-50](issue-50-type-error-null-checkout-params.md) — тот же класс отказа (`TypeError` на данных
чекаута), закрыт для `handleOrderActionCreate`.
[issue-88](issue-88-shipping-logo-undefined-index.md) — соседний случай «ядро отдало не то, что
ожидали» в этом же классе.
Правило B2a в [RULES.md](../concept/RULES.md).
