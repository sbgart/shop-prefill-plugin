# Issue 53 — Предзаполнение пишет пустые значения в сессию каждому посетителю

**Статус:** ⬜ Открыта
**Приоритет:** 🟠 Средний (производительность / чистота данных)
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

Так как хук `frontend_head` работает на **всех** страницах магазина, а `prefill.on_entry = true` по умолчанию, каждый первый визит (включая ботов и краулеров) приводит к записи в сессию двух ключей — `shop/checkout` и `shop/prefill_snapshot` — забитых `null`-ами. Плюс лог `Successfully prefilled checkout params` на каждом таком визите.

Показательно, что метод `shopPrefillPluginFillParams::hasDataForSection()` уже написан, но используется **только в debug-панели** — в самом предзаполнении он не вызывается.

## Последствия

- сессии пишутся и раздуваются для всех посетителей, даже без корзины и без прошлых заказов;
- сессия становится «непустой» → возможные конфликты с кешированием/оптимизациями магазина;
- мусорные `null` в snapshot;
- шум в логах на уровне `info`.

## Рекомендация

1. В начале `preFillCheckoutParams()` — ранний выход, если у `FillParams` нет данных ни по одной секции.
2. В `prepare*SectionParams()` писать поле только при непустом значении (как это уже сделано для `zip` в `prepareDetailsSectionParams`).
3. Использовать `hasDataForSection($section_id)` рядом с `canPrefillSection()`.
