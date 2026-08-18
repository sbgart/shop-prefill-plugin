# Issue 68 — Диалог «Мои варианты» поднимает все заказы покупателя, гидратирует все и выбрасывает всё кроме пяти

**Статус:** ⬜ Открыта; замерена на живой базе 18.08.2026 (см. «Замер» ниже)
**Приоритет:** 🟠 Средний (перф + память; страдают лучшие клиенты магазина)
**Сложность фикса:** 🔨 Средний
**Файлы:** `lib/classes/fillparams/shopPrefillPluginFillParamsProvider.class.php` (`getFillParamsCollection`), `lib/actions/frontend/shopPrefillPluginFrontendParamsChoice.action.php`, `lib/classes/orders/shopPrefillPluginOrderProvider.class.php` (`getUserOrdersId`)

## Проблема

Цепочка на открытие диалога:

1. `getUserOrdersId($contact_id)` — **без `LIMIT`**: все ID заказов покупателя за всё время.
2. `getOrdersParamsByIds($orders_ids)` — все параметры всех этих заказов одним запросом (по ~20 строк на заказ).
3. Дедупликация: строится «лёгкий» `FillParams` на каждый заказ — это сделано правильно, запросов нет.
4. **Гидратация всех уникальных вариантов**:

   ```php
   foreach ($unique_orders_params as $order_id => $order_params) {
       $fill_params = $this->getFillParamsByOrderParams($order_params, $order_id);
       $this->fill_params_collection->add($fill_params);
   }
   ```

   А `getFillParamsByOrderParams()` с непустым `$order_id` делает на каждый элемент:
   - `getOrderComment($order_id)` — SELECT;
   - `getContactIdFromOrder($order_id)` — SELECT;
   - `new waContact($contact_id)` + `getAuthData()` — загрузка контакта и всех его полей.

5. И только **после** этого экшен режет список:

   ```php
   $items = array_slice($items, 0, 5);
   ```

## Последствия

Покупатель с 200 заказами и 30 разными адресами: ~4000 строк параметров в память + ~60 SELECT + 30 загрузок контакта — ради пяти карточек. Причём `contact_id` у всех этих заказов один и тот же (это заказы одного покупателя), то есть загрузка контакта повторяется 30 раз подряд.

Для гостей то же самое плюс запрос из [issue-63](issue-63-guest-hash-lookup-full-scan.md) без `LIMIT 1`.

## Рекомендация

1. Резать **до** гидратации. Сортировка «свежие первыми» уже есть в провайдере (`array_reverse` по ключам), поэтому лимит можно применить сразу после дедупликации, а `array_slice` в экшене оставить как страховку.
2. Вынести лимит в константу (`FillParamsCollection::DEFAULT_LIMIT = 5`) и параметр `getFillParamsCollection(int $limit = 5)` — сейчас «5» зашито в экшене, а `toArray(?int $limit)` в коллекции никто не вызывает с аргументом.
3. Ограничить выборку заказов на уровне SQL: `getUserOrdersId()` → `LIMIT 50` (с запасом на дубли), `getAllOrderIdsByGuestHash()` — так же.
4. Кэшировать контакт: в цикле `fillAuthDataFromOrder()` `contact_id` повторяется — достаточно статического кэша в `ContactProvider::getContact()`.
5. Комментарий читается отдельным SELECT на заказ (`getOrderComment`) — при батче можно взять `id, comment, contact_id` одним запросом по массиву ID.


## Замер на живой базе (18.08.2026)

`general_log` за одно открытие диалога у контакта 1 (52 заказа, показывается 5 карточек):

```
SELECT id FROM shop_order WHERE (contact_id='1') ORDER BY id DESC LIMIT 50
SELECT * FROM shop_order_params WHERE `order_id` IN ('85','84','83', … 50 штук)
SELECT id, contact_id, comment FROM shop_order WHERE (id='58')
… ещё 12 таких же, по одному на заказ …
```

Итого **15 запросов**, из них 13 — построчные чтения `shop_order` через `getOrderRow()`.
`LIMIT 50` в первом запросе уже есть, так что «все заказы за всё время» — неточность
исходной формулировки: потолок 50, но 13 отдельных PK-запросов вместо одного `IN ()`
остаются, и до пяти карточек доживает меньше трети выбранного.

Важно: на общую стоимость страницы это не влияет — путь срабатывает только по явному
клику на «Мои варианты», не на каждом запросе. Контракт issue-63 не нарушен.
