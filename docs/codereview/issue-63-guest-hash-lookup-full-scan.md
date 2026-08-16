# Issue 63 — Поиск заказов гостя сканирует `shop_order_params` на каждой странице витрины

**Статус:** ⬜ Открыта
**Приоритет:** 🔴 Высокий (деградация магазина под нагрузкой, проявится только у покупателя плагина)
**Сложность фикса:** 🔨 Средний (нужна своя таблица + миграция)
**Файлы:** `lib/classes/orders/shopPrefillPluginOrderProvider.class.php` (`getLastOrderIdByGuestHash`, `getAllOrderIdsByGuestHash`), `lib/classes/fillparams/shopPrefillPluginGuestHashStorage.class.php`

## Проблема

Гостевой сценарий ищет заказ по значению параметра:

```php
"SELECT order_id FROM shop_order_params
 WHERE name = s:name AND value = s:hash
 ORDER BY order_id DESC
 LIMIT 1"
```

Индексы таблицы (`wa-apps/shop/lib/config/db.php:560`):

```php
':keys' => array(
    'PRIMARY' => array('order_id', 'name'),
    'name'    => 'name',
),
```

По `value` индекса нет. MySQL возьмёт индекс `name` и переберёт **все** строки `name='prefill_guest_hash'` — то есть по одной на каждый гостевой заказ за всю историю магазина, — а затем отсортирует их filesort'ом.

`shop_order_params` — одна из самых больших таблиц Shop-Script (десятки строк на заказ). На магазине с 50 000 гостевых заказов это 50 000 просмотренных строк.

## Где это выполняется

Не «иногда при оформлении», а на горячем пути:

1. `shopPrefillPlugin::frontendHead()` → `FrontendHooks::handleFrontendHead()` → `getFillParams()` → `getFillParamsForGuest()`. Хук `frontend_head` срабатывает на **каждой** странице витрины — главная, категория, карточка товара, поиск.
2. `checkoutBeforeAuth` → тот же путь, на **каждом** AJAX `calculate`/`create` (а их за одно оформление десятки).
3. `getFillParamsCollection()` (диалог «Мои варианты») — уже без `LIMIT 1`, то есть выбирает **все** строки, см. [issue-68](issue-68-params-choice-collection-n-plus-1.md).

Итого: один лишний тяжёлый запрос на каждый хит любого гостя. Для авторизованных путь другой (`shop_order.contact_id` — там индекс есть), так что проблема ровно в гостевом режиме, который включён по умолчанию (`prefill.guest.enabled = true`).

## Последствия

- Рост нагрузки на БД пропорционально числу заказов магазина: чем успешнее магазин, тем хуже работает плагин.
- На локальной разработке и у первых покупателей невидимо (сотни заказов), выстреливает у крупного клиента — худший сценарий для платного плагина.

## Рекомендация

1. Своя таблица связи вместо `shop_order_params`:

   ```php
   'shop_prefill_guest_order' => [
       'order_id' => ['int', 11, 'null' => 0],
       'hash'     => ['varchar', 64, 'null' => 0],
       ':keys'    => [
           'PRIMARY' => 'order_id',
           'hash'    => ['hash', 'order_id'],
       ],
   ],
   ```

   Запись — в `GuestHashStorage::saveGuestHashToOrder()`, чтение — в `OrderProvider`. Для совместимости с уже установленными копиями — миграция в `lib/updates/`, переносящая существующие `prefill_guest_hash` из `shop_order_params`.
2. До миграции (или как дополнение) — не ходить в БД там, где результат не нужен: `getFillParams()` вызывается в `frontendHead` даже при `prefill.on_entry = false`, а ассеты не требуют данных заказа вовсе. См. [issue-64](issue-64-assets-loaded-on-every-page.md).
3. Мемоизация в рамках запроса даст немного: waEvent создаёт новый экземпляр плагина на каждый хук (см. [issue-73](issue-73-stale-plugin-singleton.md)), поэтому кэш должен быть статическим, а не полем объекта.
