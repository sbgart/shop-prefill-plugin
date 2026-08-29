# План: вариант доставки — единственная идентичность

**Создан:** 23.08.2026
**Источник:** [issue-84](../codereview/issue-84-prefill-inconsistent-across-groups.md) §1, уточнено в разборе — тип доставки функционально определяется вариантом
**Правило-основание:** [Z5, Z5a](../concept/RULES.md) — минимум группы держится на устойчивом к короткому замыканию поле; вариант ⇒ тип, не наоборот
**Статус:** ✅ Все 5 этапов закоммичены и проверены в браузере 23.08.2026

## Зачем

`shipping_type_id` — параметр, который писал **только сам плагин** (`OrderHooks::saveShippingType()` из хука `order_action.create`), и он был избыточен с самого начала: ядро определяет тип доставки из выбранного варианта (`shipping_id` + `shipping_rate_id`), а не наоборот —

```php
// wa-apps/shop/lib/classes/checkout2/shopCheckoutShippingStep.class.php:226-234
$selected_variant_id = ifset($data, 'input', 'shipping', 'variant_id', null);
if (empty($selected_variant_id)) {
    $selected_type_id = ifset($data, 'input', 'shipping', 'type_id', null);   // ← только когда варианта нет
}
...
$selected_type_id = $type['id'];   // :253 — тип выводится из выбранного варианта
```

Наш параметр появился только у части истории: на тестовой базе (86 заказов) `shipping_rate_id` есть у 85, наш `shipping_type_id` — у 26 (плюс 50 легаси-строк со значением `'0'`, которое `empty()` тоже считает пустым). Гейт диалога «Мои варианты» (`empty($shipping_id) || empty($shipping_type_id)`) требовал именно наш параметр — и отсекал 60 заказов из 85: весь самовывоз (`rate_id === '0'`) и всю историю до установки плагина.

Отсюда же и issue-84 §1: `getShippingVariantId()` возвращает `null`, если нет `rate_id`, а голый `type_id` всё равно уезжал в сессию — предзаполняя пустую вкладку типа без выбранного варианта. Это нарушение Z2 (свёрнутый блок обещает готовность, которой нет): `SECTION_DATA_FIELDS['shipping']` держался на `type_id`, и дзен сворачивал группу `delivery` над недоделанным выбором.

## Что получается

| Сейчас | После |
|---|---|
| Идентичность доставки — пара `type_id` + `variant_id`, могут разойтись | Идентичность — только `variant_id` |
| Наш параметр `shop_order_params.shipping_type_id` | Не пишется и не читается вовсе |
| Гейт «Мои варианты» требует наш параметр (26 из 86 заказов) | Требует только `shipping_id` + `shipping_rate_id` (85 из 86) |
| Минимум группы `delivery` — `shipping.type_id` | `shipping.variant_id` |
| Дедуп `isSameDeliveryOption()` учитывает наш тип | Учитывает только вариант — два заказа с одинаковым вариантом, но разными историческими значениями типа (`todoor`/`bnp_todoor_2`), схлопываются в одну карточку |

## Ловушки, вокруг которых спроектировано

1. **`empty('0') === true`.** У самовывоза `rate_id === '0'` (52 заказа из 85 на тестовой базе, инстанс «Пункт выдачи заказов»). Новый гейт (`shopPrefillPluginFillParamsHelper::deliveryVariantId()`) различает пустоту как `stripEmptyLeaves()` — `null`/`''`, не `empty()`.
2. **Симметрия гидратации `isSameDeliveryOption()`.** Убирать `shipping_type_id` с одной стороны сравнения (сессия/заказ) без другой — та же ловушка, что уже задокументирована для `shipping_plugin` (`FillParamsProvider`, ~527): `is_current` перестал бы совпадать вообще. Обе точки правились одним коммитом (этап 5).
3. **Z5 держался на неверном объяснении.** Правило утверждало, что `type_id` переживает короткое замыкание конвейера, «потому что живёт в состоянии JS-контроллера» — это не подтверждается кодом (`Shipping.getData()` сериализует ту же форму, что и `Details.getData()`). Устойчивость установлена **замером** (таблица в [zen-collapse-on-upstream-checkout-error.md](../bugs/zen-collapse-on-upstream-checkout-error.md), пункт 3): `variant_id` в замере присутствует во всех трёх точках наравне с `type_id`. Z5 переписан на «установлено замером», не на ложный механизм.

## Этапы

### Этап 1. Минимум дзена — `variant_id` — ✅ 3f7401d
`SectionChecker::SECTION_DATA_FIELDS['shipping']` → `['variant_id']`. Тест `SectionCheckerOwnershipVsDataTest` (блок 9): фикстура и минимум группы переведены на `variant_id`, добавлена явная проверка «только `type_id`, без `variant_id` — НЕ минимум» и случай `variant_id = '37.0'` (самовывоз). 158 проверок, мутационно проверено (откат константы красит новые ассерты).

### Этап 2. Гейт «Мои варианты» — по варианту — ✅ fcd736c
Новый `shopPrefillPluginFillParamsHelper::deliveryVariantId()` — та же идентичность, что и `FillParams::getShippingVariantId()`, посчитанная до гидратации объекта. Гейт в `collectUniqueDeliveryOrderIds()` заменён с `empty($shipping_id) || empty($shipping_type_id)` на `deliveryVariantId(...) === null`. Новый тест `FillParamsHelperDeliveryVariantIdTest` (10 случаев + страж-round-trip к `FillParams`), мутационно проверен.

**Проверено в браузере:** заказ 71 (Ишим, `shipping_type_id = NULL` в БД) появился в диалоге «Мои варианты» — раньше гейт отсекал его безусловно.

### Этап 3. Плагин перестаёт писать `type_id` в сессию — ✅ a84564c
`prepareShippingSectionParams()` пишет только `variant_id` (безусловно, `null` включительно — `stripEmptyLeaves()` в `applyPrefill()` выбрасывает `null`, `deepMergeArrays()` в `applyDeliveryAddress()` явно затирает устаревший вариант). Кастомные поля доставки (`shipping_custom`) гейтятся тем же условием — они принадлежат варианту, не секции (issue-60). `applyDeliveryAddress()` дополнительно `unset()`-ит стоявший в сессии `type_id` после слияния: явный выбор карточки замещает секцию целиком.

**Проверено в браузере:** Reset & Refill — в `shop/checkout.order.shipping` только `variant_id`, ключа `type_id` нет вовсе. Выбор карточки со сменой типа `post→pickup` — `type_id` не появился, ядро само отрендерило «СДЭК (ПВЗ NSK2)» по одному `variant_id`.

### Этап 4. Плагин перестаёт писать параметр заказа — ✅ 5596c2b
Удалены `OrderProvider::storeShippingTypeId()` и `OrderHooks::saveShippingType()`; заодно ушла зависимость `OrderHooks` от `waRequest` (использовалась только здесь).

**Проверено в браузере:** оформлен реальный заказ (id 92, контакт 1, СДЭК ПВЗ, наличные). В параметрах — `shipping_id`, `shipping_rate_id`, core-параметр `shipping_type = pickup`; нашего `shipping_type_id` нет. `wa-log/prefill.plugin.log` чист, `Order creation hook processed successfully` — молчаливого разрыва DI (его проглотил бы `Throwable`-страж в `shopPrefill.plugin.php`) не произошло.

### Этап 5. Свойство удалено — ✅ cd50967
Атомарно, одним коммитом: свойство `FillParams::$shipping_type_id`, аксессоры, членство в `$shipping_params` (сравнение `isSameDeliveryOption()`), обе точки гидратации (`FillParamsProvider` — сессия и заказ). `hasDataForSection('shipping')` переведён на `getShippingVariantId() !== null` — метод остаётся без вызовов, но зарезервирован как примитив для issue-84 §2.

Тест `FillParamsSameDeliveryOptionTest`: убран `setShippingTypeId`, добавлен случай `rate_id = '0'` (отличается от `''`/`null`, равен другому `'0'`) и структурный страж через `ReflectionClass`. Мутационная проверка показала: страж ловит регресс жёстче, чем закладывалось — при возврате поля в `$shipping_params` PHP выдаёт `Undefined property notice` на каждом сравнении, а не тихий `false`.

**Проверено в браузере:** холодный рендер с товаром в корзине предзаполнил доставку из заказа 92 без Reset & Refill (обычный путь `checkout_before_auth`). Заказ 82 (тот же вариант СДЭК/NSK2, что и новый 92) корректно схлопнулся дедупом в одну карточку с 92 — освободившийся слот открыл заказ 70 (Пермь), не видимый на предыдущих этапах из-за лимита в 10 карточек.

## Побочный эффект: дедуп грубеет

Без `shipping_type_id` в `$shipping_params` исторические заказы с одинаковым вариантом, но разными записанными типами (`todoor` vs `bnp_todoor_2` — значение стороннего чекаут-кастома, не настоящий тип ядра), схлопываются в одну карточку вместо двух визуально одинаковых. Правильный результат: тип не был отличающей силой, вариант — был.

## Смежное: подсветка «Мои варианты» — блокирует релиз

[Баг устаревшей подсветки](../bugs/params-choice-stale-highlight-after-type-switch.md) сегодня случайно маскируется сравнением по `shipping_type_id`: в окне, где ядро держит новый `type_id` рядом со старым `variant_id`, несовпадение типа даёт «ничего не подсвечено» — честное «не знаю». После этого плана сравнение остаётся только по `variant_id`, оба поля совпадают в этом окне — подсветится карточка, с которой покупатель только что ушёл. Тихая неопределённость становится уверенной неправдой.

Решение принято, но **не входит в этот план** — отдельная задача (JS + `FrontendParamsChoice.action.php`, сравнение по `variant_id` из скрытого поля формы вместо сессии), которая **обязана выйти в одном релизе** с этим планом. Ни один не публикуется в одиночку.

## Документы

- [issue-84 §1](../codereview/issue-84-prefill-inconsistent-across-groups.md) — закрыт этим планом
- [issue-60](../codereview/issue-60-cross-section-write-details-custom.md) — снапшот-часть снята архитектурой (снапшота больше нет), кросс-секционная запись `details.custom` из `shipping`-кода частично сужена (гейтится вариантом), но не убрана — issue остаётся открытой
- [RULES.md Z5, Z5a](../concept/RULES.md)
- [TESTS.md](../tests/TESTS.md)
