# Issue 66 — `setShippingVariantId()` молча теряет вариант, если в rate_id есть точка

**Статус:** ⬜ Открыта
**Приоритет:** 🟠 Средний (ломается на популярных плагинах доставки — СДЭК, ПВЗ, постаматы)
**Сложность фикса:** ⚡ Минутный
**Файл:** `lib/classes/fillparams/shopPrefillPluginFillParams.class.php:459`

## Проблема

```php
public function setShippingVariantId(string $variant_id): void
{
    $parts = explode('.', $variant_id);

    if (count($parts) === 2) {          // ← вся запись под этим условием
        if ($parts[0] !== '' && $parts[1] !== '') {
            $this->setShippingId($parts[0]);
            $this->setShippingRateId($parts[1]);
        }
    }
}
```

`variant_id` в Shop-Script — это `{shop_plugin.id}.{rate_id}`. `rate_id` формируют сами плагины доставки, и точки в нём — обычное дело (`5.pickup.MSK123`, `12.cdek.PVZ-4419`). Тогда `count($parts)` равен трём, условие не выполняется, и метод **не делает ничего** — без ошибки, без записи в лог.

Что показательно: в соседнем классе тот же разбор сделан правильно — `shopPrefillCheckoutState::getShippingInstanceId()` использует `explode('.', $selected_variant_id, 2)`. То есть автор уже знал про лимит, но в `FillParams` он не проставлен.

## Последствия

`setShippingVariantId()` вызывается из `getFillParamsByCheckoutParams()` — того самого метода, который строит «текущий сценарий доставки» для подсветки активной карточки:

```php
// shopPrefillPluginFrontendParamsChoice.action.php
$current = $instance->getFillParamsProvider()->getFillParamsByCheckoutParams($checkout_params);
$item_array['is_current'] = $item_obj->isSameDeliveryOption($current);
```

Для ПВЗ-доставок `$current` остаётся без `shipping_id`/`shipping_rate_id` →

- в диалоге «Мои варианты» ни одна карточка не подсвечивается как текущая (или подсвечивается не та — из-за null-совпадения, см. [issue-67](issue-67-same-delivery-option-null-match.md));
- сравнение вариантов между собой становится грубее, чем задумано.

## Рекомендация

```php
$parts = explode('.', $variant_id, 2);

if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
    $this->setShippingId((int) $parts[0]);
    $this->setShippingRateId($parts[1]);
}
```

Заодно: `setShippingId(?int)` сейчас получает строку — в PHP 7.4 без `strict_types` это тихое приведение, а нечисловая первая часть даст `TypeError`. Явный `(int)` снимает вопрос.

Проверить в браузере: заказ через ПВЗ (СДЭК/Boxberry) → открыть «Мои варианты» → карточка этого заказа должна быть отмечена активной.
