# Issue 55 — Для гостя `getFillParams($id)` игнорирует переданный ID заказа

**Статус:** ⬜ Открыта
**Приоритет:** 🟡 Средний (функциональная некорректность)
**Сложность фикса:** 🔧 Небольшой
**Файлы:** `lib/classes/fillparams/shopPrefillPluginFillParamsProvider.class.php` (`getFillParams`, `getFillParamsForGuest`), `lib/actions/frontend/shopPrefillPluginFrontendApplyDelivery.controller.php`, `...FillCheckoutParams`

## Проблема

```php
public function getFillParams(?int $fill_params_id = null): shopPrefillPluginFillParams
{
    if ($this->user_provider->isAuth()) {
        return $this->getFillParamsForAuthorized($fill_params_id);
    }
    return $this->getFillParamsForGuest();   // ← $fill_params_id потерян
}
```

Для авторизованного проверка владения заказом сделана корректно (`getContactIdFromOrder` сравнивается с `contact_id`) — **IDOR отсутствует**, это хорошо. Но для гостя выбранный `order_id` просто отбрасывается, и применяется последний заказ по гостевому хешу.

Контроллеры `prefill/apply-delivery` и `prefill/fill-checkout-params` при этом отвечают `status: ok` — то есть тихо применяют **не тот** вариант, который выбрал пользователь.

Сейчас частично маскируется UI: `ParamsChoiceManager.renderLink()` рисует кнопку «Мои варианты» только при `isAuth === true`, поэтому гость до диалога обычно не доходит. Но эндпоинт публичный, а коллекция вариантов для гостей (`getFillParamsCollection`) строится полноценно — то есть функциональность наполовину реализована.

## Рекомендация

Либо реализовать выбор для гостя честно:

```php
private function getFillParamsForGuest(?int $order_id = null): shopPrefillPluginFillParams
{
    $guest_hash = $this->guest_hash_storage->getOrCreateGuestHash();
    if ($order_id && in_array($order_id, $this->order_provider->getAllOrderIdsByGuestHash($guest_hash), true)) {
        // заказ принадлежит этому гостевому хешу — используем его
    }
    ...
}
```

либо явно возвращать ошибку («выбор варианта доступен только авторизованным»), чтобы поведение не расходилось с ответом `ok`. Заодно решить, показывать ли кнопку «Мои варианты» гостям — сейчас коллекция для них считается, но не используется.
