# `$delivery_schedule` всегда пуст в Zen-сводке — читается не тот источник данных

**Статус:** причина найдена, не исправлено.

## Наблюдение

29.08.2026, живой чекаут (не curl — нужен реальный JS/CSS рендер), способ доставки «Пункт выдачи
заказов» (`sd`, `shop_plugin.id=37`), вручную заполнена полная фикстура: адрес, координаты,
расписание на неделю (включая пометку «Обед 13:00–14:00» у понедельника), «Как добраться»,
«Дополнительно», 2 фото.

На **одном и том же** GET `/order/` (без пересчёта, без AJAX):

- Развёрнутый ядровый виджет (`wa-apps/shop/templates/actions/frontend/order/form/details.html`)
  показывает «Часы работы» полностью — таблицу на 7 дней с пометкой у понедельника.
- Zen-сводка того же блока (свёрнутая карточка плагина, шаблон с `{$delivery_schedule}` в позиции,
  где он гарантированно виден) — **не показывает расписание вообще**, ни заголовка, ни данных. Проверено
  через `innerHTML` `.prefill-zen-summary`: между соседними полями (`delivery_description` и
  `delivery_photos_html`) буквально нет ни строки текста, ни пустого блока — маркер `{if $delivery_schedule}`
  просто не сработал, значение оказалось пустой строкой.

Остальные поля из того же источника (`custom_data['pickup']`) — `delivery_way`, `delivery_storage_days`,
`delivery_pickup_address` — отрендерились корректно в той же сводке. Проблема точечная, у одного
конкретного поля.

## Причина (найдена при чтении исходников, не исправлялось)

`shopPrefillCheckoutState::getShippingScheduleHtml()` берёт вариант через `getSelectedVariant()`:

```php
return $this->params['data']['shipping']['selected_variant']
    ?? $this->params['vars']['shipping']['shipping_rate']
    ?? [];
```

Ни один из этих двух источников не содержит `pickup_schedule` в структурированном виде
(`['days' => [...]]`). Ядро строит `pickup_schedule` **только** внутри
`shopCheckoutDetailsStep::process()` (`wa-apps/shop/lib/classes/checkout2/shopCheckoutDetailsStep.class.php:243-256`):

```php
$updated_selected_variant = shopCheckoutShippingStep::prepareShippingVariant(...);
if (!empty($updated_selected_variant['custom_data']['pickup']['schedule'])) {
    // ...
    $updated_selected_variant['pickup_schedule'] = shopCheckoutShippingStep::formatPickupSchedule(...);
}
```

и кладёт результат **только** в возвращаемый `vars` этого шага — `'shipping_rate' => $updated_selected_variant`
(строка 344), то есть в `$params['vars']['details']['shipping_rate']`. Именно оттуда его читает
`details.html` (`$details.shipping_rate.pickup_schedule.days`). В `$params['data']['shipping']['selected_variant']`
(откуда в первую очередь читает плагин) эта промотация никогда не записывается — она посчитана заново
на каждый рендер и живёт только в `vars` шага `details`, а плагин `vars.details.shipping_rate` не
проверяет вовсе — только `vars.shipping.shipping_rate` (другой шаг, без этой промотации).

Из-за этого `$delivery_schedule` пуст **всегда**, независимо от того, свежий ли расчёт или простая
загрузка страницы — путь `vars.details.shipping_rate.pickup_schedule.days` в коде плагина не встречается
ни разу.

## Кого это касается

Затрагивает любой способ доставки, отдающий `custom_data['pickup']['schedule']` в структурированном
виде (массив `weekdays`/`workdays`/`weekend`) — не только `sd`: та же промотация в
`shopCheckoutDetailsStep` применяется одинаково ко всем ПВЗ-плагинам (`regionalpickup`, `sydsek`,
`pickup`), значит поле нерабочее у всех них.

## Воспроизведение

1. Настроить любой ПВЗ-способ доставки с расписанием (`Настройки → Доставка → {метод} → Рабочее время`).
2. В Zen-редакторе шаблонов (K-07) вставить `{$delivery_schedule}` в шаблон группы `delivery`.
3. Оформить заказ с этим способом, свернуть карточку доставки.
4. Сравнить с развёрнутым ядровым видом того же самого запроса (кнопка «Изменить») — у ядра
   расписание есть, у сводки плагина — нет.
