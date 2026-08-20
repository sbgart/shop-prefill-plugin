# Issue 84 — предзаполнение собирает несогласованный заказ из разных источников

**Статус:** ⬜ Открыта
**Приоритет:** 🟡 Средний
**Сложность фикса:** 🔧 Небольшой (п.1) + 🧩 Требует решения (п.2)
**Файлы:** `lib/classes/sessionstorage/shopPrefillPluginSessionStorageProvider.class.php` (`applyPrefill`, `prepareShippingSectionParams`), `lib/classes/fillparams/shopPrefillPluginFillParams.class.php` (`getShippingVariantId`)
**Смежные:** [issue-65](issue-65-prefill-overrides-current-input.md) — связность **внутри** группы; здесь — **между** источниками и секциями

Найдено при разборе issue-65. Обе протечки существуют независимо от направления слияния и от списка владения: они рвут связность до того, как дело доходит до merge.

## 1. `type_id` без `variant_id`

`getShippingVariantId()` возвращает `null`, если нет хотя бы одной из половин:

```php
// shopPrefillPluginFillParams::getShippingVariantId()
if (! is_null($this->getShippingId()) && ! is_null($this->getShippingRateId())) {
    return $this->getShippingId() . '.' . $this->getShippingRateId();
}
return null;
```

`getShippingTypeId()` при этом отдаёт значение. Дальше пара расходится окончательно:

```php
// prepareShippingSectionParams()
$final_params['order']['shipping']['type_id']    = $fill_params->getShippingTypeId();
$final_params['order']['shipping']['variant_id'] = $fill_params->getShippingVariantId();   // null
...
$final_params = shopPrefillPluginHelper::stripEmptyLeaves($final_params);                  // null выброшен
```

В `$filled_order` уезжает один `type_id`. В POST при этом может лежать `variant_id` от прежнего выбора — и `deepMergeArrays` его сохраняет, потому что перекрывать нечем. Итог: тип из прошлого заказа, вариант из текущей сессии.

Когда `rate_id` пуст: заказы, оформленные способом доставки, у которого тариф не сохранился, а также данные из старых версий плагина.

**Фикс:** пара атомарна. Нет `variant_id` — не писать и `type_id`:

```php
$variant_id = $fill_params->getShippingVariantId();
if ($variant_id !== null) {
    $final_params['order']['shipping']['type_id']    = $fill_params->getShippingTypeId();
    $final_params['order']['shipping']['variant_id'] = $variant_id;
}
```

Заодно проверить `prepareShippingSectionParams()`: он пишет `custom`-поля **в чужую секцию** `details` ([issue-60](issue-60-cross-section-write-details-custom.md)) — они тоже принадлежат варианту и должны уезжать только вместе с ним.

## 2. Секции заполняются независимо друг от друга

```php
// applyPrefill()
foreach (self::SECTIONS as $section_id) {
    if ($checker->canPrefillSection($section_id, $checkout_params)) {
        $available[] = $section_id;
    }
}
```

Каждая секция решает за себя. Но вариант доставки зависит от адреса, а не только от собственной секции: адрес принадлежит покупателю (новый город — свой, или от cityselect), `shipping.type_id` пуст → подставляем вариант из прошлого заказа под чужой адрес. Пункт самовывоза при этом может физически находиться в другом городе.

Частично прикрыто сигналом `prefill_delivery_unavailable` (`renderDeliveryUnavailableScript()`), но это лечение симптома после того, как ядро не нашло вариант.

**Варианты решения** — выбрать при фиксе:

- **A. Группа delivery атомарна.** `region`, `details`, `shipping` заполняются только вместе; занята любая — не заполняем ни одну. Совпадает с дзен-группами и с тем, как данные связаны на самом деле. Цена: предзаполнение доставки станет срабатывать заметно реже.
- **B. Зависимость в одну сторону.** `shipping` не предзаполняем, если `region`/`details` пришли не из нашего источника. Мягче A, но нужна пометка происхождения секции — сейчас её нет.
- **C. Оставить как есть, усилить сигнал.** Признать, что ядро само отвергнет невалидный вариант, и довести `prefill_delivery_unavailable` до внятного сообщения покупателю.

Рекомендация — A: она же закрывает п.1 как частный случай и не требует нового состояния.

## Проверка

1. Заказ со способом доставки без сохранённого `rate_id` → новый чекаут → в форме не появляется тип без варианта.
2. Прошлый заказ с курьером по Москве → сменить город на Казань (руками или через cityselect) → пересчёт → курьерский вариант московского тарифа не подставляется.
3. Обычный сценарий: покупатель с историей, город не менял → доставка предзаполняется как раньше.
