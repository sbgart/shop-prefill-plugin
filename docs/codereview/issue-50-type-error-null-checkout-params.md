# Issue 50 — БЛОКЕР: TypeError в `handleOrderActionCreate` при пустой checkout-сессии

**Статус:** ⬜ Открыта
**Приоритет:** 🔴 Блокер релиза
**Сложность фикса:** 🔧 Тривиальный
**Файл:** `lib/classes/hooks/shopPrefillPluginOrderHooks.class.php:57-60, 79`

## Проблема

```php
$checkout_params = $this->session_storage->getCheckoutParams();   // ?array — может быть null
$this->saveShippingType($order_id, $checkout_params);             // private function saveShippingType(int $order_id, array $checkout_params)
```

`getCheckoutParams()` возвращает `?array` (ключ сессии `shop/checkout` может отсутствовать), а параметр метода типизирован как `array`. PHP 7.4 **не приводит `null` к `array`**:

```
TypeError: Argument 2 passed to saveShippingType() must be of the type array, null given
```

Как и в [issue-49](issue-49-fatal-storefront-null-backend-order-create.md), `TypeError` не ловится `catch (Exception)` в `waEvent` → 500 на создании заказа.

### Когда сессия пуста

- заказ создаётся в бэкенде / через API / CLI / импортом (у этого «пользователя» нет checkout-сессии);
- на фронтенде, если ключ `shop/checkout` ни разу не записывался (все секции предзаполнения выключены + сценарий, где ядро ещё не писало сессию).

Сейчас маскируется issue-49 (падает раньше), проявится сразу после его фикса.

## Рекомендация

```php
$checkout_params = $this->session_storage->getCheckoutParams() ?: [];
```

и/или сменить сигнатуру на `?array $checkout_params`. Заодно проверить все прочие места, где результат `?array`-геттеров уходит в типизированные параметры.
