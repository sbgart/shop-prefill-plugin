# `use_storage` ядра гасится — но не в основном нашей записью

**Статус:** ✅ Закрыто 11.09.2026 как неприменимо. Причина шире и куда менее «наша», чем предполагала исходная формулировка в TODO — см. «Рекомендация» и решение ниже.
**Источник:** бэклог-пункт TODO.md (найдено 08.09.2026 при разборе [f01](../../plans/done/zen-customer-gate-source-alignment.md)), уточняющий вопрос пользователя 11.09.2026 («мы правда что-то теряем?»)
**Смежное:** [issue-65](../../codereview/done/issue-65-prefill-overrides-current-input.md) (случай 11), [план по f01](../../plans/done/zen-customer-gate-source-alignment.md) (отвергнутый вариант 3)

## Коротко

Исходная формулировка бэклога: «предзаполнение пишет в сессию до расчёта флага `use_storage`, поэтому восстановление формы из `localStorage` после смерти PHP-сессии не срабатывает почти никогда».

Живой замер 11.09.2026 (три curl-прогона на чистых банках кук, ниже) показал: это верно только для **одной из двух** групп посетителей, и это не самая уязвимая группа.

| Кто | Пишет ли prefill в сессию | `use_storage` в HTML | Кто реально гасит флаг |
|---|---|---|---|
| Авторизованный/гость **с историей заказов** | Да (`Successfully prefilled checkout params`) | `false` | **Мы** |
| Гость **без единого заказа** | Нет (`no params were filled (empty final_params)`), подтверждено кодом и контрольным прогоном с полностью выключенным плагином | `false` | **Плагин «SEO-регионы»** — не имеет отношения к prefill |

Гость без истории — ровно та аудитория, для которой потеря `use_storage` стоила бы дороже всего (у нас для него нет данных на замену). Замер показывает: её ломает не prefill.

## Измерения

Все три — curl, свежая банка кук (`rm -f jar`), товар `product_id=847&sku_id=2268` в корзину, один `GET /order/`. Позиция в `wa-log/prefill.plugin.log` фиксировалась до и после каждого прогона.

### 1. Admin (contact_id=1, есть реальная история — заказ #134), свежая сессия, плагин включён

```
curl -c admin.jar -b admin.jar https://wa-dev.loc/webasyst/  -d login=admin -d password=123 -d wa_auth_login=1 -d remember=0
curl -c admin.jar -b admin.jar -X POST https://wa-dev.loc/cart/add/ -d 'product_id=847&sku_id=2268&quantity=1'
curl -c admin.jar -b admin.jar https://wa-dev.loc/order/
```

Лог того же запроса:
```
[DEBUG] Fill params loaded from last order   {"order_id": 134}
...
[INFO] Successfully prefilled checkout params   {"sections": ["region","shipping","details","payment"]}
[DEBUG] Prefill applied in checkoutBeforeAuth
```
HTML: `use_storage: false`.

Плагин реально писал в `shop/checkout['order']` в этом самом запросе — здесь диагноз TODO верен.

### 2. Гость без истории, свежая банка, плагин включён

```
curl -c guest.jar -b guest.jar https://wa-dev.loc/
curl -c guest.jar -b guest.jar -X POST https://wa-dev.loc/cart/add/ -d 'product_id=847&sku_id=2268&quantity=1'
curl -c guest.jar -b guest.jar https://wa-dev.loc/order/
```

Лог:
```
[DEBUG] Section 'auth' can be prefilled
... (все шесть секций can be prefilled)
[DEBUG] Prefill was evaluated but no params were filled (empty final_params)
```

Плагин **не вызвал** `setCheckoutParams()` — по коду это заведомо так: [SessionStorageProvider.php:264](../../../lib/classes/sessionstorage/shopPrefillPluginSessionStorageProvider.class.php#L264), `setCheckoutParams()`/`deepMergeArrays()` вызываются только `if (!empty($final_params))`, а `stripEmptyLeaves()` на предыдущей строке гарантированно обнуляет секции, собранные из одних `null` (история этого — [issue-53](../../codereview/done/issue-53-empty-prefill-writes-session.md)).

HTML тем не менее: `use_storage: false`.

### 3. Контрольный прогон — тот же сценарий, что и №2, но плагин `prefill` полностью выключен

Killswitch `shop_prefill_settings.active` (id=330) временно переведён `1 → 0` (с явного разрешения пользователя), прогнан сценарий №2 ещё раз, затем возвращён обратно `0 → 1` (проверено `SELECT`, вернулось).

```
curl -c guest2.jar -b guest2.jar https://wa-dev.loc/
curl -c guest2.jar -b guest2.jar -X POST https://wa-dev.loc/cart/add/ -d 'product_id=847&sku_id=2268&quantity=1'
curl -c guest2.jar -b guest2.jar https://wa-dev.loc/order/
```

В HTML нет ни одного упоминания `prefill` (плагин действительно не грузился) — и `use_storage: false` **тот же самый**.

**Вывод из №2+№3: гашение `use_storage` для гостя без истории не имеет отношения к prefill вообще.** Оно происходит и без нас.

## Настоящая причина для гостя без истории — плагин «SEO-регионы»

`session_is_alive` считается в [`shopCheckoutViewHelper::formVars()`](../../../../../lib/classes/checkout2/shopCheckoutViewHelper.class.php#L428-L439) как `!empty($session_checkout['order'])`, где `$session_checkout` читается **до** запуска чекаут-конвейера этого же запроса. Значит, чтобы флаг оказался `true` («сессия жива») уже на первом хите `/order/`, что-то должно записать `shop/checkout['order']` **раньше** — не обязательно в этом же запросе, а в любом более раннем запросе той же сессии (заход на главную, в каталог, на карточку товара).

Именно так и происходит: `regions` (SEO-регионы) регистрирует свою автоопределение города на хук `routing` — [`shopRegionsPlugin::routing()`](../../../../regions/lib/shopRegions.plugin.php#L77), который срабатывает на **каждый** фронтенд-запрос (это резолюция маршрута, а не что-то специфичное для чекаута). Через `shopRegionsUpdateCurrentContactShippingHandlerAction::execute()` → `shopRegionsAddressPatcher::patchAll()` → [`patchCheckoutOrderRegionAddress()`](../../../../regions/lib/classes/shopRegionsAddressPatcher.class.php#L59-L92) пишет:

```php
$checkout['order']['region'] = [
    'country' => $city->getCountryIso3(), 'region' => ..., 'city' => ..., 'zip' => ...
];
wa()->getStorage()->set('shop/checkout', $checkout);
```

Условие срабатывания — настройка `auto_select_city_enable`, включённая **по умолчанию** (`shopRegionsSettings::$default_settings`) и не переопределённая на этом стенде (`SELECT ... FROM shop_regions_settings WHERE name='auto_select_city_enable'` — пусто, живёт на дефолте). `getCurrentCity()` при отсутствии сохранённого выбора падает на `getDefaultForStorefront()` — то есть город находится **всегда**, даже для первого визита без единой куки. Гейт `if (!empty($checkout['order']['region']) ...) return;` защищает только от повторной записи, не от первой.

Практический эффект: на любом магазине с этим плагином и этой настройкой `shop/checkout['order']` перестаёт быть пустым для гостя **на первом же просмотре любой страницы** — главной, категории, карточки товара, — задолго до чекаута. К моменту, когда покупатель доходит до `/order/`, `session_is_alive` уже `true` независимо от prefill, авторизации или истории заказов.

(Гейт `patchCheckoutOrderRegionAddress()`/`patchCheckoutContactAddress()` сам проверяет `getAuthContact()` и не пишет для уже авторизованных — поэтому в прогоне №1 (admin) причина именно prefill, а не regions: для авторизованного regions ничего не делает, но prefill делает.)

## Что это значит для идеи «пусть сначала отработает восстановление ядра, потом мы» (обсуждалась раньше)

Идея была: отложить запись prefill на один запрос конкретно в ветке «сессия была пуста при входе», чтобы дать `localStorage`-восстановлению ядра шанс сработать первым.

После этого замера её ценность нужно пересчитать вниз: на **этом стенде** восстановление и так структурно не работает для подавляющего большинства визитов — не из-за нас, а из-за regions. Отложенный prefill помог бы только в узком клине: посетитель, чей **самый первый запрос на весь сайт за сессию** — это прямая ссылка на `/order/` (не главная, не каталог, не карточка товара) **и** при этом либо `regions` выключен/без auto-select, либо посетитель уже авторизован (regions его не трогает, но у авторизованного, если есть история, вместо него включится prefill — тот же результат). На практике такой вход с холодной сессии сразу на `/order/` — редкость (обычно человек сначала листает каталог).

Значит чинить именно **предзаполнение** ради восстановления `use_storage` — низкая отдача: даже полностью убрав нашу часть проблемы, для большинства визитов ничего не изменится, потому что её тушит другой, более ранний и не подчинённый нам механизм.

## Рекомендация

1. **Часть про гостя без истории — закрыть как не относящуюся к prefill.** Это не наш баг: воспроизводится один в один с полностью выключенным плагином. Если он важен для продукта — это разговор с regions (или отключение `auto_select_city_enable` там, где `use_storage` приоритетнее авто-геолокации), не задача этого плагина.
2. **Часть про пользователя/гостя с историей — наша, но, по обсуждению в диалоге 11.09.2026, невысокого приоритета.** Единственная реальная потеря — покупатель поменял что-то (способ оплаты, адрес) в чекауте, не оформил заказ, ушёл на 24+ минут (TTL сессии), вернулся — вместо своей несохранённой правки увидит данные последнего оформленного заказа, а не черновик. Это откат к другому осмысленному источнику, а не к пустой форме — цена невысокая, симметрично тому, что происходит и при обычном повторном визите без`use_storage` вообще.
3. Если всё же захочется починить пункт 2 — направление обсуждалось: не писать сессию на самом первом `GET /order/`, если она была пуста **до** этого запроса, дать браузеру дошлить `calculate` (с `localStorage`, если он есть, или без — тогда предзаполнение сработает штатно на этом втором проходе). Работоспособность зависит от `regions`: если посетитель уже «испортил» сессию визитом на другую страницу до чекаута — пункт 3 всё равно не поможет, см. раздел выше.

Решение, что делать (закрыть целиком / оставить п.2 в бэклоге с той же низкой оценкой / что-то ещё) — за пользователем.
