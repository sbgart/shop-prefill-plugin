# Посекционные флаги предзаполнения (Per-Section Prefill Flags)

## Проблема

### Текущее поведение

При ошибке валидации (например, некорректный email) Shop-Script частично стирает данные из хранилища при повторном обновлении формы.

**Сценарий:**
1. Пользователь заполнил: доставка ✅, оплата ✅, email ❌
2. Submit → валидация → ошибка email
3. Первый reload формы → данные ВСЁ ЕЩЁ в хранилище
4. Второй reload формы (смена количества товара):
   - Инпуты доставки/оплаты НЕ видимы (Zen Mode) → НЕ отправляются в POST
   - Shop-Script считает "данных нет" → **СТИРАЕТ хранилище**
   - **Данные доставки и оплаты потеряны**

### Почему текущее решение не работает

Используется **глобальный** флаг `checkout_params['prefill_metadata']['first_prefill_done']`:
- После первого предзаполнения флаг = `true`
- Даже если Shop-Script стёр данные секций, флаг остаётся
- Плагин НЕ предзаполняет повторно → секции остаются пустыми

---

## Предложенное решение

### Механизм: Посекционные флаги `_pf`

Вместо одного глобального флага использовать флаг **для каждой секции**, размещённый **внутри данных секции**:

```php
// При предзаполнении:
$checkout_params['order']['auth']['data']['_pf'] = 1;
$checkout_params['order']['shipping']['_pf'] = 1;
$checkout_params['order']['payment']['_pf'] = 1;
$checkout_params['order']['details']['shipping_address']['_pf'] = 1;
$checkout_params['order']['region']['_pf'] = 1;
$checkout_params['order']['confirm']['_pf'] = 1;
```

### Как это работает

1. **Предзаполнение:** При заполнении секции устанавливается флаг `_pf` внутри данных
2. **Стирание:** Когда Shop-Script стирает `order['auth']['data']`, флаг `_pf` стирается **автоматически**
3. **Проверка:** Перед предзаполнением проверяем:
   - Есть ли флаг `_pf`? → Да → НЕ заполняем (уже заполняли)
   - Нет флага `_pf` И нет данных? → Заполняем заново + ставим флаг

### Где размещать флаги

**Вариант C (выбран):** Внутри данных секции

| Секция     | Размещение флага                              |
| ---------- | --------------------------------------------- |
| `auth`     | `order['auth']['data']['_pf']`                |
| `region`   | `order['region']['_pf']`                      |
| `shipping` | `order['shipping']['_pf']`                    |
| `details`  | `order['details']['shipping_address']['_pf']` |
| `payment`  | `order['payment']['_pf']`                     |
| `confirm`  | `order['confirm']['_pf']`                     |

**Плюсы:**
- ✅ Флаг **гарантированно** стирается вместе с данными секции
- ✅ Не зависит от логики очистки Shop-Script
- ✅ Простая реализация

### Изменения в коде

#### 1. Добавить методы работы с флагами

```php
// В shopPrefillPluginSessionStorageProvider

private function isSectionPrefilled(string $section, array $checkout_params): bool
{
    switch ($section) {
        case 'auth':
            return !empty($checkout_params['order']['auth']['data']['_pf']);
        case 'region':
            return !empty($checkout_params['order']['region']['_pf']);
        case 'shipping':
            return !empty($checkout_params['order']['shipping']['_pf']);
        case 'details':
            return !empty($checkout_params['order']['details']['shipping_address']['_pf']);
        case 'payment':
            return !empty($checkout_params['order']['payment']['_pf']);
        case 'confirm':
            return !empty($checkout_params['order']['confirm']['_pf']);
    }
    return false;
}

private function setSectionPrefilledFlag(string $section, array &$final_params): void
{
    switch ($section) {
        case 'auth':
            $final_params['order']['auth']['data']['_pf'] = 1;
            break;
        case 'region':
            $final_params['order']['region']['_pf'] = 1;
            break;
        case 'shipping':
            $final_params['order']['shipping']['_pf'] = 1;
            break;
        case 'details':
            $final_params['order']['details']['shipping_address']['_pf'] = 1;
            break;
        case 'payment':
            $final_params['order']['payment']['_pf'] = 1;
            break;
        case 'confirm':
            $final_params['order']['confirm']['_pf'] = 1;
            break;
    }
}
```

#### 2. Изменить логику `preFillCheckoutParams()`

```php
public function preFillCheckoutParams(shopPrefillPluginFillParams $params): void
{
    if ($this->prefilled) {
        return; // Уже вызвали в ЭТОМ HTTP-запросе
    }

    $checkout_params = $this->getCheckoutParams();
    $checkout_params = is_array($checkout_params) ? $checkout_params : [];

    // УБРАТЬ проверку глобального флага first_prefill_done
    
    $final_params = [];
    $checker = $this->getSectionChecker();

    // Для каждой секции НЕЗАВИСИМО:
    foreach (['auth', 'region', 'shipping', 'details', 'payment', 'confirm'] as $section) {
        // Проверяем: можно ли заполнить? (нет данных + нет флага _pf)
        if ($checker->canPrefillSection($section, $checkout_params) 
            && !$this->isSectionPrefilled($section, $checkout_params)) {
            
            // Заполняем секцию
            $method = 'prepare' . ucfirst($section) . 'SectionParams';
            $this->$method($params, $final_params);
            
            // Ставим флаг _pf для этой секции
            $this->setSectionPrefilledFlag($section, $final_params);
        }
    }

    if (!empty($final_params)) {
        $this->setCheckoutParams(
            shopPrefillPluginHelper::deepMergeArrays($checkout_params, $final_params)
        );
    }

    $this->prefilled = true;
}
```

#### 3. Добавить предзаполнение в AJAX-обновления

```php
// В shopPrefill.plugin.php -> checkoutRenderAuth()

public function checkoutRenderAuth(&$params) {
    // ===== ПРЕДЗАПОЛНЕНИЕ (для AJAX reload формы) =====
    if ($storefront_settings['prefill']['active']) {
        $fill_params = $this->getFillParamsProvider()->getFillParams();
        if ($fill_params) {
            $this->getSessionStorageProvider()->preFillCheckoutParams($fill_params);
        }
    }
    
    // ===== ZEN MODE (существующая логика) =====
    // ...
}
```

---

## Нерешённая проблема (CRITICAL)

### Потеря изменений пользователя

**Сценарий:**
1. Предзаполнение из БД → доставка "Курьер"
2. Пользователь меняет → доставка "Самовывоз"
3. Ошибка email → Shop-Script стирает данные доставки + флаг `_pf`
4. Плагин видит: нет флага → предзаполняет "Курьер" из БД
5. ❌ **Выбор пользователя "Самовывоз" потерян!**

### Возможные решения

#### Вариант 1: Глобальный флаг "was_prefilled_at_least_once"

**Идея:** После первого предзаполнения НЕ предзаполнять повторно никогда.

```php
if (!isset($checkout_params['prefill_metadata']['was_prefilled'])) {
    preFillAll();
    $checkout_params['prefill_metadata']['was_prefilled'] = true;
} else {
    return; // Уже предзаполняли — НЕ трогаем
}
```

**Плюсы:** ✅ Простая логика, защищает изменения пользователя

**Минусы:** ❌ При глюке Shop-Script форма остаётся пустой (не восстанавливается)

---

#### Вариант 2: Snapshots предзаполненных данных

**Идея:** Сохранять "слепок" предзаполненных значений и сравнивать с текущими.

```php
// При предзаполнении:
$checkout_params['prefill_metadata']['snapshots']['shipping'] = ['type_id' => 1];

// При проверке:
$current = $checkout_params['order']['shipping']['type_id'];
$snapshot = $checkout_params['prefill_metadata']['snapshots']['shipping']['type_id'];

if ($current !== $snapshot) {
    // Пользователь ИЗМЕНИЛ → НЕ предзаполняем
    return;
}

if (empty($current) && !empty($snapshot)) {
    // Данные СТЁРТЫ, но совпадали с предзаполненными → предзаполняем
    preFill();
}
```

**Плюсы:** ✅ Точно определяем "пользователь менял" VS "Shop-Script стёр"

**Минусы:** ⚠️ Сложнее логика, нужно хранить snapshots всех полей

---

#### Вариант 3: LocalStorage + Snapshots

**Идея:** Использовать localStorage браузера для хранения snapshots.

```javascript
// При предзаполнении (JS):
localStorage.setItem('prefill_snapshots', JSON.stringify({
    shipping: { type_id: 1 },
    payment: { id: 2 }
}));
```

```php
// При проверке (PHP читает через hidden input):
$snapshots = json_decode($_POST['prefill_snapshots'] ?? '{}', true);
// ... сравниваем с текущими данными
```

**Плюсы:** ✅ Работает даже при смене PHP-сессии

**Минусы:** ⚠️ Требует JS, зависимость от localStorage

---

## Детали обсуждения

См. conversation ID: `0f0e825b-cf93-4e76-9ea2-470a3917ee7f` (2026-02-09)
