# Логика работы настройки `guest/consent_required`

## Описание настройки

**Местоположение:** `lib/config/storefront.settings.php:28`

```php
'guest' => [
    'consent_required' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
]
```

Эта настройка управляет тем, **требуется ли согласие гостя** на сохранение его данных для предзаполнения.

## Логика работы

### Когда `guest/consent_required = true` (по умолчанию)

✅ **Галочка согласия отображается** на странице оформления заказа  
✅ **Проверяется** состояние cookie `prefill_consent`  
✅ **`guest_hash` сохраняется** в заказ **только если** гость поставил галочку

**Поведение:**
- Гость видит галочку "Запомнить мои данные для следующего заказа"
- Если гость **не ставит** галочку → `guest_hash` **не сохраняется** → данные **не предзаполнятся** при следующем заказе
- Если гость **ставит** галочку → `guest_hash` **сохраняется** → данные **предзаполнятся** при следующем заказе

### Когда `guest/consent_required = false`

❌ **Галочка согласия НЕ отображается**  
❌ **Игнорируется** состояние cookie `prefill_consent`  
✅ **`guest_hash` сохраняется автоматически** в каждый заказ

**Поведение:**
- Гость **не видит** галочку согласия
- `guest_hash` **всегда сохраняется** в заказ (как будто согласие есть всегда)
- Данные **всегда предзаполняются** при следующем заказе

## Реализация

### 1. Отображение галочки (`checkoutRenderConfirm`)

**Файл:** `lib/shopPrefill.plugin.php:498-524`

```php
// Показываем галочку согласия только для неавторизованных И если требуется согласие
if (!$this->getUserProvider()->isAuth()) {
    $storefront_settings = $this->getStorefrontSettings();
    $consent_required = $storefront_settings['guest']['consent_required'];
    
    // Показываем галочку только если согласие требуется
    if ($consent_required) {
        $has_consent = $this->getConsentStorage()->hasConsent();
        $html .= shopPrefillPluginViewProvider::render(
            'checkout/ConsentCheckbox',
            ['has_consent' => $has_consent]
        );
    }
}
```

### 2. Сохранение `guest_hash` (`orderActionCreate`)

**Файл:** `lib/shopPrefill.plugin.php:600-612`

```php
// Для неавторизованных: сохраняем хеш гостя
// Логика: если согласие не требуется ИЛИ оно получено - сохраняем хеш
if (!$this->getUserProvider()->isAuth()) {
    $storefront_settings = $this->getStorefrontSettings();
    $consent_required = $storefront_settings['guest']['consent_required'];
    $has_consent = $this->getConsentStorage()->hasConsent();
    
    // Сохраняем хеш если: согласие не требуется ИЛИ оно получено
    if (!$consent_required || $has_consent) {
        $guest_hash = $this->getGuestHashStorage()->getOrCreateGuestHash();
        $this->getGuestHashStorage()->saveGuestHashToOrder($order_id, $guest_hash);
    }
}
```

## Таблица решений

| Ситуация | `guest/consent_required` | Галочка | Cookie `prefill_consent` | `guest_hash` сохраняется? |
|----------|-------------------------|---------|-------------------------|---------------------------|
| Гость не ставил галочку | `true` | Показывается | `0` или отсутствует | ❌ Нет |
| Гость поставил галочку | `true` | Показывается | `1` | ✅ Да |
| Администратор отключил требование | `false` | Не показывается | **Игнорируется** | ✅ Да (всегда) |

## Преимущества такой логики

1. **Гибкость для администратора** — можно включить/выключить запрос согласия
2. **Соответствие GDPR** — когда `guest/consent_required = true`, пользователь контролирует сохранение своих данных
3. **Удобство** — когда `guest/consent_required = false`, данные всегда предзаполняются без лишних вопросов
4. **Предсказуемость** — игнорирование старых cookie при отключении требования предотвращает путаницу

## Связанные файлы

- `lib/shopPrefill.plugin.php` — основная логика
- `lib/config/storefront.settings.php` — определение настройки
- `templates/checkout/ConsentCheckbox.html` — шаблон галочки
- `lib/actions/frontend/shopPrefillPluginFrontendConsent.controller.php` — обработка согласия
- `lib/classes/consent/shopPrefillPluginConsentStorage.class.php` — работа с cookie
- `js/frontend.js` — обработка галочки на фронтенде
