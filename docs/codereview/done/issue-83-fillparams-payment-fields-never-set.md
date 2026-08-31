# Issue 83 — `FillParams`: три сеттера никто не вызывает, `getPaymentName()` всегда `null`

**Статус:** ✅ Решена (19.08.2026)
**Приоритет:** 🟢 Низкий
**Сложность фикса:** ⚡ Минутный (две строки в провайдере) либо удаление мёртвого кода
**Файлы:** `lib/classes/fillparams/shopPrefillPluginFillParams.class.php`, `lib/classes/fillparams/shopPrefillPluginFillParamsProvider.class.php`

## Проблема

В `shopPrefillPluginFillParams` есть поля `payment_name`, `payment_plugin`, `shipping_plugin` с геттерами и сеттерами, но **сеттеры не вызываются нигде в проде** — только `setShippingPlugin()` в `tests/FillParamsSameDeliveryOptionTest.php`. Значит `getPaymentName()`, `getPaymentPlugin()`, `getShippingPlugin()` всегда возвращают `null`.

При этом данные в БД есть. Параметры последнего заказа на локалке:

```
payment_id     16
payment_name   Наличные
payment_plugin cash
shipping_id    33
shipping_name  Бесплатная доставка курьером
```

`getFillParamsByOrderParams()` читает соседний `shipping_name` (строка ~447), а `payment_name` и `payment_plugin` пропускает.

## Почему это не выстрелило

Предзаполнению эти поля не нужны: в сессию чекаута пишутся идентификаторы (`payment[id]`, `shipping[type_id]`), а названия и логотипы считает ядро при рендере. Поля лежат мёртвым грузом с тех пор, как объект писался «на вырост».

## Когда выстрелит

Любая попытка показать название способа оплаты **до** первого удачного расчёта чекаута. Ровно на это наткнулся разбор посева кэша сводки в [баге zen-collapse](../../bugs/zen-collapse-on-upstream-checkout-error.md) (отвергнутый вариант 6): `payment_name` — признак «данные группы есть» в `shopPrefillPluginZenSummaryCache::PRESENCE_FIELDS`, и посев группы `payment` без него не заработал бы вовсе.

## Решение

Принят вариант 1 — заполнять. Две строки в `getFillParamsByOrderParams()` рядом с `payment_id`:

```php
if (isset($order_params['payment_name'])) {
    $fill_params->setPaymentName($order_params['payment_name']);
}
if (isset($order_params['payment_plugin'])) {
    $fill_params->setPaymentPlugin($order_params['payment_plugin']);
}
```

Удаление отвергнуто: поля не мёртвые, а лишь незаполненные — они уже участвуют в логике.
`payment_plugin` проверяет `hasDataForSection('payment')`, `shipping_plugin` — `hasDataForSection('shipping')`
и `isSameDeliveryOption()` (через `$shipping_params`), а `payment_name`/`payment_plugin` копирует
`mergePaymentParams()` (через `$payment_params`). Вычистить их — это правка четырёх мест плюс теста,
против двух строк на заполнение.

### Поправка к разбору: `shipping_plugin` в БД есть

Утверждение выше («`shipping_plugin` в `shop_order_params` не хранится») **неверно** — проверено запросом
на локалке: 80 записей `shipping_plugin` против 89 `shipping_id` (пустые — доставки без плагина).
Заказ 87: `shipping_plugin = sd`, `payment_plugin = cash`.

Но заполнять его всё равно нельзя, и по более серьёзной причине. `shipping_plugin` входит в
`$shipping_params`, по которым сравнивает `isSameDeliveryOption()`, а сравнение асимметрично по источникам:

| Сторона | Источник | `shipping_plugin` |
|---|---|---|
| `$item_obj` в `ParamsChoiceAction` | `getFillParamsByOrderParams()` — `shop_order_params` | был бы `'sd'` |
| `$current` там же | `getFillParamsByCheckoutParams()` — сессия чекаута | всегда `null` |

В сессии чекаута плагина нет вовсе: только `shipping[type_id]` и `shipping[variant_id]`. После issue-67
`null` больше не wildcard, поэтому `'sd'` vs `null` дало бы «разные варианты» — и `is_current` в выборе
адреса погас бы на всех карточках. Причина зафиксирована комментарием в самом провайдере, рядом с
заполнением `payment_*`.

## Тесты

Автотест не добавлялся. Тесты плагина принципиально без Webasyst (см. [TESTS.md](../../tests/TESTS.md)), а
`shopPrefillPluginFillParamsProvider` тянет пять провайдеров в конструкторе — на две строки маппинга
стенд не окупается. Существующий набор прогнан после правки: 3 файла, 132 проверки — зелёные.
