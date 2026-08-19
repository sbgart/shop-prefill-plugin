# Issue 65 — `applyPrefillInput()` льёт предзаполнение **поверх** данных текущего запроса

**Статус:** ⬜ Открыта
**Приоритет:** 🟠 Средний
**Сложность фикса:** ⚡ Минутный (поменять порядок аргументов), но нужен тест
**Файлы:** `lib/classes/checkout/shopPrefillCheckoutState.class.php` (`applyPrefillInput`), `lib/classes/hooks/shopPrefillPluginCheckoutHooks.class.php` (`handleCheckoutBeforeAuth`)

> **Уточнение 19.08.2026.** Эскалация до 🔴 «после фикса issue-59» больше не грозит: issue-59 закрыли разделением константы, а не удалением `html`. Страховка №2 ниже сохранена в неизменном виде и закреплена тестом `tests/SectionCheckerOwnershipVsDataTest.php` (блок 8 — дословная сверка `SECTION_OWNERSHIP_FIELDS`). Пункт 2 рекомендации («делать вместе с issue-59») неактуален — чинится самостоятельно.

## Проблема

```php
public function applyPrefillInput(array $filled_order): void
{
    $this->params['data']['input'] = shopPrefillPluginHelper::deepMergeArrays(
        $this->params['data']['input'],   // base
        $filled_order                     // override — выигрывает
    );
}
```

`deepMergeArrays($base, $override)` отдаёт приоритет второму аргументу. То есть данные из прошлого заказа перекрывают то, что покупатель прислал в этом самом POST. Направление должно быть обратным: предзаполнение — это заполнение **пробелов**, а не источник истины.

## Почему до сих пор не выстрелило

Две случайные страховки:

1. **`/order/calculate/`** — ядро пишет POST в сессию **до** `processAll()`:

   ```php
   // wa-apps/shop/lib/actions/frontend/order/shopFrontendOrder.actions.php:33-38
   $session_checkout['order'] = $input;
   wa()->getStorage()->set('shop/checkout', $session_checkout);
   ```

   Поэтому к моменту хука `checkout_before_auth` `canPrefillSection()` видит свежие данные и в `$filled_order` их не кладёт.

2. **Ключ `html`** из [issue-59](issue-59-html-key-marks-section-filled.md) держит секции `auth`, `region`, `details`, `confirm` в состоянии «заполнено» и глушит предзаполнение вообще.

## Где страховок нет

`createAction()` **не** пишет POST в сессию перед `processAll('create', ...)` — он вызывает его напрямую с `waRequest::post()`. Значит на `/order/create/` в сессии лежит состояние с предыдущего `calculate`, а во входе — актуальная форма. Любое расхождение (покупатель поправил поле и сразу нажал «Оформить», не спровоцировав пересчёт) даёт заказ со **старым** значением.

И главное: как только уберут `html` из `SECTION_KEY_FIELDS` (а это план issue-59), обе страховки исчезнут одновременно, и баг станет воспроизводимым: очищенное покупателем поле в текущем POST будет считаться «секция пуста» → предзаполнение → перезапись.

## Рекомендация

1. Поменять направление слияния:

   ```php
   $this->params['data']['input'] = shopPrefillPluginHelper::deepMergeArrays(
       $filled_order,
       $this->params['data']['input']
   );
   ```

   и убедиться, что `is_prefilled` по-прежнему выставляется корректно (сейчас он ставится безусловно, хотя merge мог ничего не изменить).
2. Делать это **до** или **вместе** с issue-59 — иначе фикс issue-59 приносит регресс.
3. Тест: на чекауте очистить комментарий → сразу «Оформить заказ» без промежуточного пересчёта → в заказе не должно появиться значение из прошлого заказа.
