# Валидация полей перед скрытием

Shop-Script имеет **3 типа ошибок валидации**, которые нужно проверять перед скрытием полей формы.

## 1. Критические ошибки

**Расположение:** `$params['errors']`, `$params['error_step_id']`

**Характеристики:**
- Блокируют продолжение checkout
- Влияют на расчет стоимости/доступности доставки
- Появляются сразу при загрузке страницы

**Примеры полей:**
- Город (от него зависит стоимость доставки)
- Страна
- Регион

**Структура:**
```php
$params['errors'] = [
    [
        'name'    => 'region[city]',
        'text'    => 'Это поле является обязательным.',
        'section' => 'region',
    ],
    // ...
]
```

## 2. Отложенные ошибки (delayed_errors)

**Расположение:**
- `$params['data']['auth']['delayed_errors']` - поля авторизации
- `$params['data']['details']['delayed_errors']` - поля доставки

**Характеристики:**
- НЕ блокируют показ кнопки "Подтвердить заказ"
- НЕ влияют на расчет доставки
- Проверяются только при создании заказа (в `confirm` step)
- Появляются сразу при загрузке страницы

**Примеры полей:**
- Телефон
- Отчество
- Дополнительные/кастомные поля

**Структура:**
```php
$params['data']['auth']['delayed_errors'] = [
    'auth[data][phone]' => 'Это поле является обязательным.',
    // ...
]

$params['data']['details']['delayed_errors'] = [
    'details[shipping_address][zip]' => 'Это поле является обязательным.',
    // ...
]
```

## 3. Ошибки согласия (service_agreement)

**Расположение:** `$params['data']['auth']['fields']`, `$params['data']['auth']['service_agreement']`

**Характеристики:**
- Чекбокс согласия с условиями обслуживания
- НЕ попадает в `delayed_errors`
- Проверяется только при создании заказа
- Требует отдельной проверки через структуру полей

**Проверка:**
```php
// Ищем поле service_agreement в списке полей
foreach ($params['data']['auth']['fields'] as $field) {
    if ($field['name'] === 'service_agreement') {
        // Если поле обязательное и не заполнено - ошибка
        if (!empty($field['required']) && empty($params['data']['auth']['service_agreement'])) {
            // НЕЛЬЗЯ скрывать форму
        }
    }
}
```

## Проверка перед скрытием полей

**Хуки для проверки:**
- `checkout_render_auth` - после обработки секции авторизации
- `checkout_render_shipping` - после обработки секции доставки
- `checkout_render_confirm` - финальная проверка всех ошибок ✅

**Код проверки:**

```php
public function checkoutRenderConfirm(&$params)
{
    // 1. Критические ошибки
    $has_critical_errors = !empty($params['errors']);
    
    // 2. Отложенные ошибки
    $has_delayed_errors = !empty($params['data']['auth']['delayed_errors']) 
                       || !empty($params['data']['details']['delayed_errors']);
    
    // 3. Service agreement
    $service_agreement_error = false;
    if (isset($params['data']['auth']['fields'])) {
        foreach ($params['data']['auth']['fields'] as $field) {
            if ($field['name'] === 'service_agreement') {
                if (!empty($field['required']) && empty($params['data']['auth']['service_agreement'])) {
                    $service_agreement_error = true;
                    break;
                }
            }
        }
    }
    
    if ($has_critical_errors || $has_delayed_errors || $service_agreement_error) {
        // НЕЛЬЗЯ скрывать поля - есть незаполненные обязательные поля
        return;
    }
    
    // ✅ Все ок - можно скрывать поля и показывать краткую форму
    // ...
}
```

## Важно

**Проверяй ВСЕ 3 типа ошибок!** Иначе можешь скрыть:
- Поле "Город" (критическая ошибка)
- Поле "Телефон" (отложенная ошибка)  
- Чекбокс "Согласие с условиями" (service_agreement)

И пользователь не сможет их заполнить.
