# Issue 30 — Два отдельных экземпляра `shopOrderParamsModel` в плагине

**Статус:** ✅ Закрыта  
**Приоритет:** 🟢 Косметика  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `shopPrefill.plugin.php`, методы `getOrderProvider` и `getGuestHashStorage`

## Проблема

`shopOrderParamsModel` создаётся дважды независимо:

```php
public function getOrderProvider(): shopPrefillPluginOrderProvider
{
    return $this->order_provider ??= new shopPrefillPluginOrderProvider(
        new shopOrderModel(),
        new shopOrderParamsModel()  // ← первый экземпляр
    );
}

public function getGuestHashStorage(): shopPrefillPluginGuestHashStorage
{
    return $this->guest_hash_storage ??= new shopPrefillPluginGuestHashStorage(
        $this->getUserProvider(),
        new shopOrderParamsModel(),  // ← второй экземпляр
        wa()->getResponse()
    );
}
```

`OrderProvider` и `GuestHashStorage` работают с одной и той же таблицей `shop_order_params` через разные объекты модели.

## Решение

Добавлены приватные геттеры `getOrderModel()` и `getOrderParamsModel()` в `shopPrefillPlugin`, которые используют уже объявленные поля `$shop_order_model` и `$shop_order_params_model`. Оба провайдера получают модели через эти геттеры — без лишних зависимостей между `OrderProvider` и `GuestHashStorage`.
