# Issue 59 — Ключ `html` считается данными: предзаполнение и snapshot-восстановление молча отключаются

**Статус:** ⬜ Открыта
**Приоритет:** 🟠 Средний (функция тихо перестаёт работать)
**Сложность фикса:** 🔧 Небольшой
**Файл:** `lib/classes/sections/shopPrefillPluginSectionChecker.class.php` (`SECTION_KEY_FIELDS`)

## Проблема

В списке ключевых полей, по которым секция признаётся заполненной, стоит `html`:

```php
private const SECTION_KEY_FIELDS = [
    'auth'     => ['data.email', 'data.phone', 'data.firstname', 'html'],
    'region'   => ['city', 'html'],
    'shipping' => ['type_id'],
    'details'  => ['shipping_address.street', 'html'],
    'payment'  => ['id'],
    'confirm'  => ['comment', 'html'],
];
```

`html` — не данные покупателя, а служебный флаг рендера: им фронтенд ядра просит вернуть HTML секции. `wa-apps/shop/js/frontend/order/form.js` кладёт его в каждый запрос:

```js
result.push({ name: "region[html]", value: "only" });   // 707, 713, 726
result.push({ name: "auth[html]",    value: 1 });        // 297, 310
// то же для shipping, details, payment, confirm
```

А `shopFrontendOrder.actions.php:33-38` пишет **весь POST** в сессию:

```php
$session_checkout['order'] = $input;
wa()->getStorage()->set('shop/checkout', $session_checkout);
```

Значит после **первого же** взаимодействия с формой в `$_SESSION['shop/checkout']['order']` лежат `auth.html`, `region.html`, `details.html`, `confirm.html`. `isSectionFilled()` возвращает `true`, если заполнено **любое** ключевое поле → эти четыре секции навсегда (до конца сессии) считаются заполненными.

## Последствия

- `canPrefillSection()` для `auth`, `region`, `details`, `confirm` = `false` → предзаполнение по ним не выполняется;
- `getSnapshotSection()` дополнительно вызывает `isSectionFilled($section_id, $snapshot)`. Снапшот — копия ранее смерженных checkout-параметров, то есть тоже с `html`. Секция считается «наполненной» по служебному флагу, и из снапшота может восстановиться блок, где реальных данных нет;
- по факту оба механизма надёжно работают только для `shipping` и `payment` — единственных секций без `html` в списке.

Сценарий: покупатель очистил email в секции auth → в сессии `auth = ['html' => 1, 'data' => ['email' => '']]` → `isSectionFilled('auth')` = `true` → восстановление из снапшота не срабатывает, хотя ровно для этого случая оно и написано.

## Рекомендация

1. Убрать `html` из всех списков `SECTION_KEY_FIELDS`. Флаг рендера не является данными покупателя.
2. Проверить в браузере сценарий «пользователь намеренно очищает поле»: после правки секция снова станет доступной для предзаполнения, и снапшот вернёт прежнее значение. Это заявленное поведение плагина, но убедиться, что оно не выглядит как «поле не очищается».
3. Blast radius ограничен: `isSectionFilled()` и `canPrefillSection()` вызываются только из `shopPrefillPluginSessionStorageProvider` и debug-панели. Zen Mode их не использует.
