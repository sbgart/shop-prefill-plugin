# Issue 66 — `setShippingVariantId()` молча теряет вариант, если в rate_id есть точка

**Статус:** ✅ Закрыта 18.08.2026
**Приоритет:** 🟠 Средний (ломается на популярных плагинах доставки — СДЭК, ПВЗ, постаматы)
**Решение:** разбирать `variant_id` только по первой точке и сохранять `rate_id` целиком
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

## Что исправлено

- `explode('.', $variant_id)` заменён на `explode('.', $variant_id, 2)`;
- ID инстанса доставки явно приводится к `int` перед вызовом `setShippingId()`;
- добавлен автономный регрессионный тест `tests/FillParamsShippingVariantIdTest.php` для обычных, многоточечных и некорректных значений;
- каталог `tests` исключён из релизного архива плагина.

## Проверено 18.08.2026

### PHP 7.4

`tests/FillParamsShippingVariantIdTest.php` проходит. В том числе проверены round trip без потери хвоста:

- `5.pickup.MSK123` → `shipping_id = 5`, `shipping_rate_id = pickup.MSK123`;
- `12.cdek.PVZ-4419` → `shipping_id = 12`, `shipping_rate_id = cdek.PVZ-4419`;
- обычный `3.courier` и некорректные значения без обеих непустых частей.

### Chrome, живой checkout

На `https://wa-dev.loc/order/` применён сохранённый вариант заказа №82 — «СДЭК (ПВЗ Новосибирск, Вокзальная магистраль (NSK2))». После перезагрузки и повторного открытия «Мои варианты»:

- карточка заказа №82 получила класс `is-active` и визуальную подсветку;
- остальные четыре карточки остались неактивными;
- ошибок или предупреждений Prefill в консоли нет.

У текущего плагина СДЭК фактический `shipping[variant_id]` равен `43.NSK2:136:270`: ПВЗ-сценарий проходит весь интеграционный путь, но его `rate_id` не содержит точки. Read-only проверка `shop_order_params` также не нашла существующих заказов с точкой в `shipping_rate_id`. Поэтому конкретный многоточечный формат подтверждён регрессионным PHP-тестом; тестовые заказы и данные каталога ради проверки не изменялись.
