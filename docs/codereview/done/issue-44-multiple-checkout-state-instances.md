# Issue 44 — `CheckoutHooks`: множественная инстанциация `shopPrefillCheckoutState`

**Статус:** ⬜ Открыта  
**Приоритет:** 🟢 Низкий  
**Сложность фикса:** 🔧 Небольшой  
**Файл:** `hooks/shopPrefillPluginCheckoutHooks.class.php`

## Проблема

В каждом render-хуке создаётся новый `shopPrefillCheckoutState`:

```php
// buildZenModeGroupBlock:
$state = new shopPrefillCheckoutState($params);

// renderSectionErrorsAndDebug:
$state = new shopPrefillCheckoutState($params);

// renderDeliveryUnavailableScript:
$state = new shopPrefillCheckoutState($params);
```

В методе `handleCheckoutRenderConfirm` за один вызов создается **минимум 3** объекта `shopPrefillCheckoutState` из одного и того же `$params`:

1. `renderDeliveryUnavailableScript` → `new shopPrefillCheckoutState($params)`
2. `renderZenModeConfirmStyles` → `new shopPrefillCheckoutState($params)`  
3. `renderSectionErrorsAndDebug` → `new shopPrefillCheckoutState($params)`

`shopPrefillCheckoutState` — лёгкий адаптер без SQL, поэтому влияние на производительность минимально. Но это нарушает DRY и усложняет рефакторинг.

## Рекомендация

Создавать `shopPrefillCheckoutState` один раз на хук и передавать в private-методы:

```php
public function handleCheckoutRenderConfirm(array &$params): string
{
    $state = new shopPrefillCheckoutState($params);
    
    return $this->renderDeliveryUnavailableScript($state)
        . $this->renderZenModeConfirmStyles($state)
        . $this->renderConsentCheckbox()
        . $this->renderSectionErrorsAndDebug($state, 'checkoutRenderConfirm', 'CONFIRM SECTION');
}
```
