# Функция очистки сохраненных данных предзаполнения

**Статус:** ❌ Не реализовано
**Дата создания:** 2026-01-18
**Приоритет:** Высокий (GDPR compliance)

## Проблема

В текущей реализации плагина отсутствует функция полного удаления сохраненных данных предзаполнения. Пользователи не могут удалить свои персональные данные, что нарушает требования приватности.

### Текущие возможности очистки (ограниченные):

1. **Очистка куки хеша гостя** (`clearGuestHash()`)
   - Удаляет только куку `prefill_guest_hash`
   - Данные в БД остаются

2. **Отзыв согласия** (`revokeConsent()`)
   - Удаляет куку `prefill_consent`
   - Не влияет на существующие данные

3. **Очистка сессионного хранилища** (`clearStorage`)
   - Очищает только временные данные формы
   - Данные заказов в БД сохраняются

## Требуемая функциональность

### Для неавторизованных пользователей:

Необходимо удалить все записи `prefill_guest_hash` из таблицы `shop_order_params` для текущего хеша гостя.

### Для авторизованных пользователей:

Необходимо удалить все заказы пользователя (или только параметры предзаполнения из них).

## Техническая реализация

### 1. Новый метод в GuestHashStorage

```php
/**
 * Полностью удаляет все данные предзаполнения для текущего гостя
 */
public function clearAllGuestData(): bool
{
    $guest_hash = $this->getGuestHash();
    if (!$guest_hash) {
        return true; // Нет данных для удаления
    }

    // Удаляем все prefill_guest_hash для этого хеша
    $deleted = $this->getOrderParamsModel()->deleteByField([
        'name' => self::getGuestHashParamName(),
        'value' => $guest_hash
    ]);

    // Очищаем куку
    $this->clearGuestHash();

    return $deleted > 0;
}
```

### 2. Новый метод для авторизованных пользователей

```php
/**
 * Очищает историю предзаполнения для авторизованного пользователя
 */
public function clearAllUserData(int $contact_id): bool
{
    // Получаем все заказы пользователя
    $order_ids = $this->getOrderProvider()->getUserOrdersId($contact_id);

    if (empty($order_ids)) {
        return true;
    }

    // Удаляем все prefill_guest_hash из заказов пользователя
    $deleted = 0;
    foreach ($order_ids as $order_id) {
        $deleted += $this->getOrderParamsModel()->deleteByField([
            'order_id' => $order_id,
            'name' => 'prefill_guest_hash'
        ]);
    }

    return $deleted > 0;
}
```

### 3. Расширение Consent контроллера

```php
case 'clear_data':
    $user_provider = $plugin->getUserProvider();

    if ($user_provider->isAuth()) {
        // Для авторизованных - очищаем по contact_id
        $result = $plugin->getGuestHashStorage()->clearAllUserData($user_provider->getId());
    } else {
        // Для гостей - очищаем по хешу
        $result = $plugin->getGuestHashStorage()->clearAllGuestData();
    }

    $this->response = [
        'status' => $result ? 'ok' : 'error',
        'message' => $result ? _wp('Все данные предзаполнения удалены') : _wp('Ошибка удаления данных')
    ];
    break;
```

## UI/UX аспекты

### Кнопка в интерфейсе

Добавить кнопку "Удалить все сохраненные данные" в:
- Секцию согласия на странице checkout
- Настройки пользователя (для авторизованных)
- Debug панель плагина

### Подтверждение действия

```javascript
if (confirm(_("confirm.clear_all_prefill_data"))) {
    // Выполнить очистку
}
```

## Локализация

```po
msgid "action.clear_prefill_data"
msgstr "Удалить данные предзаполнения"

msgid "confirm.clear_prefill_data"
msgstr "Вы уверены, что хотите удалить все сохраненные данные предзаполнения? Это действие нельзя отменить."

msgid "success.prefill_data_cleared"
msgstr "Все данные предзаполнения успешно удалены"
```

## Безопасность

- Проверять авторизацию пользователя перед удалением данных
- Логировать действия удаления для аудита
- Не удалять заказы полностью, только параметры предзаполнения

## Тестирование

### Тест-кейсы:

1. ✅ Очистка данных гостя - предзаполнение перестает работать
2. ✅ Очистка данных авторизованного пользователя - старые заказы не предзаполняются
3. ✅ Новые заказы после очистки - работают нормально
4. ✅ Отмена действия - данные сохраняются

## GDPR Compliance

Реализация этой функции обеспечит:
- Право на удаление персональных данных (Right to erasure)
- Контроль пользователя над своими данными
- Прозрачность обработки данных

## Связанные файлы

- `lib/classes/fillparams/shopPrefillPluginGuestHashStorage.class.php` — хранилище хеша
- `lib/actions/frontend/shopPrefillPluginFrontendConsent.controller.php` — контроллер согласия
- `lib/classes/orders/shopPrefillPluginOrderProvider.class.php` — работа с заказами
- `templates/checkout/ConsentCheckbox.html` — UI компонент

## Приоритет реализации

**Высокий** - требуется для соответствия GDPR и обеспечения приватности пользователей.