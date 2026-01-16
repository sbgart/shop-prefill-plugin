# API плагина Prefill

**Дата создания:** 2026-01-08  
**Версия:** 1.0

## Обзор API

Плагин Prefill предоставляет программный интерфейс для интеграции с другими компонентами Shop-Script и расширения функциональности.

## ⚠️ Маршрутизация AJAX-запросов

**Важно!** Для плагинов Shop-Script все AJAX-запросы к frontend-контроллерам должны использовать полный путь через приложение.

### Правильный подход ✅

```javascript
// В PHP передаем базовый URL
$js_params = [
    'appUrl' => wa()->getAppUrl('shop'),  // "/shop/"
];

// В JavaScript используем полный путь
constructor(params) {
    this.appUrl = params.appUrl;
}

$.post(this.appUrl + 'prefill/consent', { action: 'grant' })
// → /shop/prefill/consent ✅
```

### Неправильный подход ❌

```javascript
$.post('/prefill/consent', { action: 'grant' })
// → /prefill/consent ❌ (404 Not Found)
```

**См. также:** `docs/bugs/ajax-routing-404.md`


## Основные классы

### shopPrefillPlugin

Главный класс плагина. Точка входа для всех операций.

```php
// Получение экземпляра плагина
$plugin = shopPrefillPlugin::getInstance();

// Проверка активности
if ($plugin->isActive()) {
    // Плагин активен
}

// Получение настроек витрины
$settings = $plugin->getStorefrontSettings();
```

#### Методы

- `isActive(): bool` - проверка активности плагина
- `getStorefrontSettings(): array` - получение настроек текущей витрины
- `getSettingProvider(): shopPrefillPluginSettingProvider` - провайдер настроек
- `getFillParamsProvider(): shopPrefillPluginFillParamsProvider` - провайдер данных предзаполнения

### shopPrefillPluginFillParams

Класс для работы с данными предзаполнения.

```php
// Получение данных для предзаполнения
$fillParams = $plugin->getFillParamsProvider()->getFillParams();

// Структура данных
[
    'contact' => [
        'name' => 'Иван Иванов',
        'email' => 'ivan@example.com',
        'phone' => '+7 (999) 123-45-67'
    ],
    'address' => [
        'country' => 'rus',
        'region' => '77',
        'city' => 'Москва',
        'street' => 'Ленина',
        'zip' => '123456'
    ],
    'shipping' => [
        'type_id' => 1,
        'rate_id' => 'courier'
    ]
]
```

### shopPrefillPluginStorefrontProvider

Управление настройками витрин.

```php
$storefrontProvider = $plugin->getStorefrontProvider();

// Получение витрины
$storefront = $storefrontProvider->getCurrentStorefront();

// Сохранение настроек
$storefront->saveSettings([
    'prefill' => ['active' => true],
    'remember_me' => ['active' => true, 'expires' => 30]
]);
```

## Хуки (Hooks)

Плагин использует следующие хуки Shop-Script:

### frontend_order

Срабатывает на странице оформления заказа.

```php
public function frontendOrder($params)
{
    // Предзаполнение данных в корзине
    if ($this->isActive()) {
        $this->getSessionStorageProvider()->fillCheckoutParams(
            $this->getFillParamsProvider()->getFillParams()
        );
    }
}
```

### frontend_head

Срабатывает на всех страницах магазина.

```php
public function frontendHead($params)
{
    // Инициализация скриптов и стилей
    // Предзаполнение при первом посещении
    // Настройка "Запомнить меня"
}
```

### checkout_render_shipping

Управление отображением секций доставки.

```php
public function checkoutRenderShipping(&$params)
{
    // Добавление диалога выбора параметров доставки
    shopPrefillPluginCheckout::addParamsChoiceLink($params);
}
```

### order_action.create

Сохранение данных при создании заказа.

```php
public function orderActionCreate($data)
{
    // Сохранение типа доставки и комментария
    $this->getOrderProvider()->storeShippingTypeId($orderId, $typeId);
    $this->getOrderProvider()->storeComment($orderId, $comment);
}
```

## Расширение функциональности

### Кастомные провайдеры данных

```php
class CustomFillParamsProvider extends shopPrefillPluginFillParamsProvider
{
    public function getFillParams(): array
    {
        $params = parent::getFillParams();

        // Добавление кастомных данных
        $params['custom_data'] = $this->getCustomData();

        return $params;
    }

    private function getCustomData(): array
    {
        // Логика получения данных из внешнего источника
        return ['field' => 'value'];
    }
}
```

### Кастомное хранилище

```php
class CustomStorage implements shopPrefillPluginFillParamsStorage
{
    public function store(array $params, int $userId): void
    {
        // Сохранение в кастомное хранилище (Redis, внешнее API и т.д.)
    }

    public function retrieve(int $userId): array
    {
        // Получение из кастомного хранилища
        return [];
    }
}
```

### Интеграция с внешними сервисами

```php
class ExternalIntegration
{
    public static function onOrderCreate($orderId, $params)
    {
        // Синхронизация с CRM
        self::syncToCRM($orderId, $params);

        // Отправка в аналитику
        self::sendToAnalytics($orderId, $params);
    }
}
```

## Модели данных

### shop_prefill_settings

Хранение настроек плагина.

```sql
CREATE TABLE shop_prefill_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    storefront VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    value TEXT,
    UNIQUE KEY storefront_name (storefront, name)
);
```

### Структура данных заказа

```php
$orderData = [
    'contact' => [
        'firstname' => 'Иван',
        'lastname' => 'Иванов',
        'email' => 'ivan@example.com',
        'phone' => '+7 (999) 123-45-67'
    ],
    'shipping_address' => [
        'country' => 'rus',
        'region' => '77',
        'city' => 'Москва',
        'street' => 'Ленина',
        'zip' => '123456'
    ],
    'params' => [
        'shipping_type_id' => 1,
        'comment' => 'Комментарий к заказу'
    ]
];
```

## Обработка ошибок

### Исключения

```php
try {
    $params = $plugin->getFillParamsProvider()->getFillParams();
} catch (shopPrefillPluginException $e) {
    // Обработка ошибок плагина
    waLog::log($e->getMessage(), 'shop_prefill.log');
} catch (Exception $e) {
    // Обработка общих ошибок
    waLog::log($e->getMessage());
}
```

### Валидация данных

```php
class DataValidator
{
    public static function validateFillParams(array $params): bool
    {
        // Валидация структуры данных
        if (!isset($params['contact']['email'])) {
            throw new InvalidArgumentException('Email is required');
        }

        // Валидация формата
        if (!filter_var($params['contact']['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format');
        }

        return true;
    }
}
```

## Тестирование

### Unit-тесты

```php
class shopPrefillPluginTest extends PHPUnit_Framework_TestCase
{
    public function testFillParamsProvider()
    {
        $provider = new shopPrefillPluginFillParamsProvider();
        $params = $provider->getFillParams();

        $this->assertIsArray($params);
        $this->assertArrayHasKey('contact', $params);
    }
}
```

### Интеграционные тесты

```php
class PrefillIntegrationTest
{
    public function testCheckoutPrefill()
    {
        // Симуляция оформления заказа
        $checkout = new shopCheckout();
        $order = $checkout->getOrder();

        // Проверка предзаполнения
        $this->assertNotEmpty($order['contact']['name']);
    }
}
```

## Производительность

### Оптимизации

- **Кэширование** - данные предзаполнения кэшируются
- **Ленивая загрузка** - компоненты загружаются по требованию
- **Батчинг запросов** - группировка запросов к БД

### Мониторинг

```php
// Логирование производительности
$start = microtime(true);
$params = $plugin->getFillParamsProvider()->getFillParams();
$duration = microtime(true) - $start;

waLog::log("Fill params duration: {$duration}s", 'shop_prefill_performance.log');
```

## Безопасность

### Защита данных

- Все чувствительные данные шифруются
- Проверка CSRF токенов
- Валидация входных данных
- Защита от XSS атак

### Аудит

```php
class SecurityAuditor
{
    public static function logDataAccess($userId, $action, $data)
    {
        waLog::log("User {$userId} {$action}: " . json_encode($data), 'shop_prefill_security.log');
    }
}
```