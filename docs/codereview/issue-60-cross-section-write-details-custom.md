# Issue 60 — Shipping-билдер пишет в чужую секцию, восстановление details из снапшота её затирает

**Статус:** ⬜ Открыта
**Приоритет:** 🟢 Низкий (узкий сценарий, но нарушен контракт секций)
**Сложность фикса:** 🔧 Небольшой
**Файл:** `lib/classes/sessionstorage/shopPrefillPluginSessionStorageProvider.class.php` (`prepareShippingSectionParams`, `prepareDetailsSectionParams`)

## Проблема

`prepareShippingSectionParams()` пишет не только в свою секцию:

```php
if ($fill_params->getShippingCustom()) {
    foreach ($fill_params->getShippingCustom() as $param => $value) {
        $final_params['order']['details']['custom'][$param] = $value;   // ← секция details
    }
}
```

Из этого следуют две отдельные проблемы.

### 1. Обход проверки секции

Запись в `details` происходит внутри блока, защищённого `canPrefillSection('shipping')`. Если `details` уже заполнена и чекер сказал «не трогать», кастомные поля доставки всё равно туда попадут.

### 2. Затирание при восстановлении из снапшота

Порядок вызовов в `preFillCheckoutParams()`: `shipping` (строка ~211), затем `details` (~216). А снапшот-ветка `prepareDetailsSectionParams()` делает **присваивание, а не merge**:

```php
if ($snapshot_section !== null) {
    $final_params['order']['details'] = $snapshot_section;   // ← сносит details.custom
    return;
}
```

Условие достижимости: у секции `shipping` снапшота нет (иначе она уходит в свою снапшот-ветку с `return` и `details.custom` не пишет), а у `details` — есть. То есть в снапшоте нет `shipping.type_id`, но есть `shipping_address.street`. Пример: покупатель ввёл адрес, но способ доставки так и не выбрался (для его региона ничего не доступно).

Достижимость заметно выше при нерешённой [issue-59](issue-59-html-key-marks-section-filled.md): там `details` признаётся наполненной по служебному ключу `html`.

## Последствия

Кастомные поля доставки (`getShippingCustom()`) не предзаполняются, хотя данные для них есть. Тихо, без ошибок в логе.

## Рекомендация

1. В снапшот-ветках `prepare*SectionParams()` использовать `deepMergeArrays()` вместо присваивания — тогда порядок вызовов перестаёт быть значимым.
2. Либо убрать кросс-секционную запись: переложить `details.custom` в `prepareDetailsSectionParams()`, где ей и место по смыслу, а `prepareShippingSectionParams()` оставить со своей секцией. Второй вариант чище — он же снимает проблему №1 (обход `canPrefillSection('details')`).
3. Проверить остальные `prepare*` на такие же кросс-секционные записи: сейчас это единственный случай, но `prepareDetailsSectionParams()` дублирует `zip` в две секции осознанно — его трогать не нужно.
