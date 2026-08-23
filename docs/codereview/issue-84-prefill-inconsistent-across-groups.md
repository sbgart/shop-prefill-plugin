# Issue 84 — предзаполнение собирает несогласованный заказ из разных источников

**Статус:** ✅ Закрыта 23.08.2026 — п.1 закрыт фиксом; п.2 не относится к тому же фиксу и вынесена в [issue-86](issue-86-delivery-group-source-completeness.md) (по образцу issue-52 → issue-79)
**Приоритет:** —
**Сложность фикса:** —
**Файлы:** `lib/classes/sessionstorage/shopPrefillPluginSessionStorageProvider.class.php`, `lib/classes/fillparams/shopPrefillPluginFillParams.class.php`
**Смежные:** [issue-65](issue-65-prefill-overrides-current-input.md) — связность **внутри** группы; здесь была — **между** источниками и секциями; [issue-86](issue-86-delivery-group-source-completeness.md) — продолжение п.2

Найдено при разборе issue-65. Исходно описывала две отдельные протечки, существовавшие независимо от направления слияния и от списка владения.

## 1. `type_id` без `variant_id` — закрыт 23.08.2026

**Закрыт иначе, чем предлагалось изначально.** Разбор в браузере показал, что ядро вообще не смотрит на `type_id`, когда `variant_id` заполнен (`shopCheckoutShippingStep:226-234`) и само выводит тип из выбранного варианта (`:253`). Значит атомарная пара — не нужный минимум: `type_id` избыточен полностью. Он был **нашим собственным** параметром (`shop_order_params.shipping_type_id`, писал только этот плагин), покрывал 26 заказов из 86 на тестовой базе против 85 у `shipping_id`+`shipping_rate_id`, и не отображался покупателю нигде, кроме debug-дампа.

Реализация — план [delivery-variant-identity.md](../plans/delivery-variant-identity.md), 5 этапов, все проверены в браузере: `shipping_type_id` удалён из `FillParams`, из сессии, из параметров заказа; вариант (`shipping_id.rate_id`) стал единственной идентичностью доставки везде — в предзаполнении, в гейте истории «Мои варианты», в дедупликации, в минимуме группы для дзен-режима.

Первоначальный разбор предлагал писать пару `type_id`/`variant_id` атомарно:

```php
// shopPrefillPluginFillParams::getShippingVariantId() — до 23.08.2026
if (! is_null($this->getShippingId()) && ! is_null($this->getShippingRateId())) {
    return $this->getShippingId() . '.' . $this->getShippingRateId();
}
return null;
```

`getShippingTypeId()` при этом отдавал значение, и пара расходилась: `prepareShippingSectionParams()` писала оба поля безусловно, `stripEmptyLeaves()` выбрасывала `null` из `variant_id`, но `type_id` уезжал — а в POST при этом мог лежать `variant_id` от прежнего выбора, и `deepMergeArrays` его сохранял. Итог: тип из прошлого заказа, вариант из текущей сессии. Предложенный фикс (писать пару только целиком) был бы корректен, но избыточен по сравнению с полным удалением поля.

## 2. Секции заполняются независимо друг от друга — вынесена в issue-86

Раздел не относится к фиксу п.1 (не про идентичность варианта, а про полноту источника для группы `region`+`details`+`shipping`) — по правилу файла TODO.md «частично закрыли → остаток в отдельную issue» вынесена в [issue-86](issue-86-delivery-group-source-completeness.md) со всем разбором, включая уточнение 22-23.08.2026 о двух разных видах раскола (по владению — недостижим; по полноте источника — достижим и не прикрыт).
