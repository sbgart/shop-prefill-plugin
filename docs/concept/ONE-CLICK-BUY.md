# Концепция: Покупка в один клик (One-Click Buy)

> **Статус:** Концепция  
> **Дата:** 2026-01-24  
> **Версия:** 1.0

---

## Проблема

После реализации функционала предзаполнения checkout и компактного режима отображения секций, у пользователей появляется возможность оформлять заказы буквально в один клик. Логично расширить плагин функцией "Покупка в один клик", которая позволит добавить товар и сразу перейти к checkout с минимальными действиями.

**Цель:** Реализовать настоящую покупку в один клик, где пользователю не нужно заполнять никакие поля — только нажать кнопку "Купить" на странице товара и "Оформить заказ" на checkout.

---

## Исследование Shop-Script API

### Архитектура корзины

**Класс:** `shopCart`  
**Хранение:** Таблица `shop_cart_items`  
**Идентификация:** Уникальный `code` (MD5 хеш) в cookie `shop_cart`

```php
// Корзина привязана к code
$cart = new shopCart($code);

// Все товары в БД имеют этот code
// shop_cart_items: [id, code, product_id, sku_id, quantity, ...]
```

**Ключевые методы:**

| Метод                       | Описание                         |
| --------------------------- | -------------------------------- |
| `addItem($item, $services)` | Добавить товар в корзину         |
| `clear()`                   | Очистить всю корзину             |
| `items($hierarchy)`         | Получить все товары              |
| `generateCode()`            | Статический метод генерации code |

### Проблема изоляции

**Вопрос:** Можно ли вывести checkout с одним конкретным товаром, не трогая основную корзину?

**Ответ:** Нет встроенного механизма изоляции. Shop-Script работает только с одной корзиной (одним `code`) на пользователя.

---

## Варианты реализации

### ❌ Вариант 2: Backup и Restore (отклонен)

**Идея:** Сохранить текущие товары → очистить корзину → добавить один товар → восстановить после заказа.

**Минусы:**
- Риск потери данных при сбое
- Проблемы при работе в нескольких вкладках
- Сложная логика восстановления
- Нужно точно воссоздавать все параметры товаров (services, quantity, etc.)

---

### ✅ Вариант 1: Временная корзина (выбран)

**Идея:** Создать новый уникальный `code` для one-click корзины, использовать его для checkout, затем вернуться к основной корзине.

#### Преимущества

- ✅ **Изоляция:** Основная корзина не трогается вообще
- ✅ **Простота:** Используем стандартный API Shop-Script
- ✅ **Безопасность:** Нет риска потери данных основной корзины
- ✅ **Совместимость:** Prefill автоматически работает с временной корзиной
- ✅ **Компактность:** Если включен compact mode — чекаут максимально компактный
- ✅ **Многовкладочность:** Проблемы минимальны (основная корзина остается в session)

#### Недостатки

- ⚠️ Нужна очистка временных корзин в БД (cron или при восстановлении)
- ⚠️ Требуется обработка отмены покупки (кнопка "Вернуться")

---

## Архитектура решения

### Схема работы

```
1. Пользователь → "Купить в один клик" на странице товара
   ↓
2. Сохраняем текущий code корзины в session: 'shop/oneclickbackup'
   ↓
3. Генерируем новый временный code
   ↓
4. Устанавливаем cookie 'shop_cart' = temp_code
   ↓
5. Создаем временную корзину: new shopCart(temp_code)
   ↓
6. Добавляем один товар в временную корзину
   ↓
7. Устанавливаем флаг: wa()->getStorage()->set('shop/oneclick_mode', true)
   ↓
8. Редирект на checkout
   ↓
9. Checkout видит ТОЛЬКО один товар (из временной корзины)
   ↓
10. Prefill автоматически предзаполняет поля
   ↓
11. Compact mode (если включен) сворачивает секции
   ↓
12. Пользователь → "Оформить заказ"
   ↓
13. Событие order_action.create
   ↓
14. Восстанавливаем оригинальный code из 'shop/oneclickbackup'
   ↓
15. Очищаем временную корзину (опционально)
   ↓
16. Удаляем флаги: oneclick_mode, oneclickbackup
```

### Обработка отмены

Если пользователь **не оформил заказ**, нужна кнопка "Отменить / Вернуться к корзине":

```
1. Кнопка "Отменить" на checkout
   ↓
2. Контроллер shopPrefillPluginFrontendCancelOneClick
   ↓
3. Восстанавливаем оригинальный code
   ↓
4. Очищаем временную корзину
   ↓
5. Редирект на основную корзину
```

---

## Компоненты реализации

### 1. Контроллер: One-Click инициация

**Путь:** `lib/actions/frontend/shopPrefillPluginFrontendOneClick.controller.php`

```php
class shopPrefillPluginFrontendOneClickController extends waJsonController
{
    public function execute()
    {
        // Получаем параметры товара
        $product_id = waRequest::post('product_id', 0, waRequest::TYPE_INT);
        $sku_id = waRequest::post('sku_id', 0, waRequest::TYPE_INT);
        $quantity = waRequest::post('quantity', 1);
        $services = waRequest::post('services', []);
        
        if (!$product_id || !$sku_id) {
            throw new waException('Invalid product parameters');
        }
        
        // Валидация товара
        $product_model = new shopProductModel();
        $sku_model = new shopProductSkusModel();
        
        $product = $product_model->getById($product_id);
        $sku = $sku_model->getById($sku_id);
        
        if (!$product || !$sku || !$sku['available']) {
            throw new waException('Product not available');
        }
        
        // Сохраняем оригинальную корзину
        $original_code = waRequest::cookie('shop_cart');
        if ($original_code) {
            wa()->getStorage()->set('shop/oneclick_backup', $original_code);
        }
        
        // Генерируем временный code
        $temp_code = shopCart::generateCode();
        
        // Устанавливаем cookie
        $cookie_expire = time() + 3600; // 1 час (для временной корзины достаточно)
        wa()->getResponse()->setCookie('shop_cart', $temp_code, $cookie_expire, null, '', false, true);
        
        // Создаем временную корзину
        $temp_cart = new shopCart($temp_code);
        
        // Добавляем товар
        $item = [
            'product_id' => $product_id,
            'sku_id' => $sku_id,
            'quantity' => $quantity,
            'type' => 'product'
        ];
        
        $data_services = [];
        if ($services) {
            foreach ($services as $service_id => $variant_id) {
                $data_services[] = [
                    'service_id' => $service_id,
                    'service_variant_id' => $variant_id,
                ];
            }
        }
        
        $temp_cart->addItem($item, $data_services);
        
        // Флаг one-click режима
        wa()->getStorage()->set('shop/oneclick_mode', true);
        wa()->getStorage()->set('shop/oneclick_temp_code', $temp_code);
        
        // URL для checkout
        $checkout_url = wa()->getRouteUrl('shop/frontend/checkout');
        
        $this->response = [
            'redirect' => $checkout_url
        ];
    }
}
```

### 2. Контроллер: Отмена One-Click

**Путь:** `lib/actions/frontend/shopPrefillPluginFrontendCancelOneClick.controller.php`

```php
class shopPrefillPluginFrontendCancelOneClickController extends waJsonController
{
    public function execute()
    {
        if (!wa()->getStorage()->get('shop/oneclick_mode')) {
            throw new waException('Not in one-click mode');
        }
        
        // Очищаем временную корзину
        $temp_code = wa()->getStorage()->get('shop/oneclick_temp_code');
        if ($temp_code) {
            $temp_cart = new shopCart($temp_code);
            $temp_cart->clear();
        }
        
        // Восстанавливаем оригинальную корзину
        $original_code = wa()->getStorage()->get('shop/oneclick_backup');
        if ($original_code) {
            $cookie_expire = time() + 30 * 86400; // 30 дней
            wa()->getResponse()->setCookie('shop_cart', $original_code, $cookie_expire, null, '', false, true);
        }
        
        // Очищаем флаги
        wa()->getStorage()->remove('shop/oneclick_mode');
        wa()->getStorage()->remove('shop/oneclick_backup');
        wa()->getStorage()->remove('shop/oneclick_temp_code');
        
        // Редирект на корзину
        $cart_url = wa()->getRouteUrl('shop/frontend/cart');
        
        $this->response = [
            'redirect' => $cart_url
        ];
    }
}
```

### 3. Хук: Восстановление после заказа

**Путь:** В основном классе плагина `lib/shopPrefill.plugin.php`

```php
public function orderActionCreate($params)
{
    // Проверяем, был ли это one-click заказ
    if (!wa()->getStorage()->get('shop/oneclick_mode')) {
        return;
    }
    
    $temp_code = wa()->getStorage()->get('shop/oneclick_temp_code');
    
    // Очищаем временную корзину
    if ($temp_code) {
        $temp_cart = new shopCart($temp_code);
        $temp_cart->clear();
    }
    
    // Восстанавливаем оригинальную корзину
    $original_code = wa()->getStorage()->get('shop/oneclick_backup');
    if ($original_code) {
        $cookie_expire = time() + 30 * 86400; // 30 дней
        wa()->getResponse()->setCookie('shop_cart', $original_code, $cookie_expire, null, '', false, true);
    } else {
        // Если не было оригинальной корзины — просто удаляем cookie
        wa()->getResponse()->deleteCookie('shop_cart');
    }
    
    // Очищаем флаги
    wa()->getStorage()->remove('shop/oneclick_mode');
    wa()->getStorage()->remove('shop/oneclick_backup');
    wa()->getStorage()->remove('shop/oneclick_temp_code');
}
```

### 4. JavaScript: Кнопка на фронтенде

**Путь:** `js/prefill.frontend.js` (добавить в существующий)

```javascript
/**
 * One-Click Buy
 */
var PrefillOneClick = (function($) {
    'use strict';
    
    return {
        init: function() {
            $(document).on('click', '.js-one-click-buy', this.handleClick.bind(this));
        },
        
        handleClick: function(e) {
            e.preventDefault();
            
            var $btn = $(e.currentTarget);
            var productId = $btn.data('product-id');
            var skuId = $btn.data('sku-id');
            var quantity = $btn.data('quantity') || 1;
            
            if (!productId || !skuId) {
                console.error('One-Click: Missing product or sku ID');
                return;
            }
            
            // Показываем загрузку
            $btn.prop('disabled', true).addClass('loading');
            
            // AJAX запрос
            $.post('?plugin=prefill&action=oneclick', {
                product_id: productId,
                sku_id: skuId,
                quantity: quantity
            })
            .done(function(response) {
                if (response.status === 'ok' && response.data && response.data.redirect) {
                    window.location = response.data.redirect;
                } else {
                    console.error('One-Click: Invalid response', response);
                    alert('Ошибка при оформлении заказа');
                    $btn.prop('disabled', false).removeClass('loading');
                }
            })
            .fail(function(xhr) {
                console.error('One-Click: Request failed', xhr);
                alert('Ошибка при оформлении заказа');
                $btn.prop('disabled', false).removeClass('loading');
            });
        }
    };
})(jQuery);

// Инициализация
jQuery(function() {
    PrefillOneClick.init();
});
```

### 5. Шаблон: Кнопка "Купить в один клик"

**Пример интеграции в тему:**

```html
<!-- На странице товара -->
<button class="js-one-click-buy btn btn-primary"
        data-product-id="{$product.id}"
        data-sku-id="{$product.sku_id}"
        data-quantity="1">
    <i class="fas fa-bolt"></i> Купить в один клик
</button>
```

### 6. Шаблон: Кнопка "Отменить" на checkout

**Путь:** Через хук `checkout_render_*` или шаблон

```html
{if $wa->storage()->get('shop/oneclick_mode')}
    <div class="prefill-oneclick-cancel">
        <button class="js-cancel-oneclick btn btn-link">
            ← Вернуться к корзине
        </button>
    </div>
    
    <script>
    jQuery('.js-cancel-oneclick').on('click', function(e) {
        e.preventDefault();
        $.post('?plugin=prefill&action=canceloneclick', function(r) {
            if (r.status === 'ok' && r.data && r.data.redirect) {
                window.location = r.data.redirect;
            }
        });
    });
    </script>
{/if}
```

---

## Интеграция с Prefill

### Автоматическая работа

**One-Click корзина автоматически работает с prefill**, потому что:

1. ✅ Временная корзина — это обычная `shopCart` с новым `code`
2. ✅ Checkout работает через стандартный механизм (cookie `shop_cart`)
3. ✅ Prefill получает данные из cookie/session/заказов — источник не привязан к `code`
4. ✅ Предзаполнение работает **независимо** от содержимого корзины

### Совместимость с Compact Mode

Если включен **Компактный режим** (COLLAPSIBLE-SECTIONS):

```
Checkout при one-click покупке:
┌────────────────────────────────────┐
│ ☑ Товар: iPhone 15 Pro  1 шт.     │ ← Один товар
├────────────────────────────────────┤
│ 👤 Иван Петров • +7 999 123-45-67 │ ← Свернутая секция
│    [Изменить ▼]                    │
├────────────────────────────────────┤
│ 📍 Москва, ул. Ленина 15           │ ← Свернутая секция
│    [Изменить ▼]                    │
├────────────────────────────────────┤
│ 🚚 СДЭК Курьер                     │ ← Свернутая секция
│    [Изменить ▼]                    │
├────────────────────────────────────┤
│ [Оформить заказ] ← 1 клик!         │
└────────────────────────────────────┘
```

**Это и есть настоящая покупка в один клик!**

---

## Настройки плагина

### Новая вкладка "Покупка в один клик"

Добавить в `storefront.settings.php`:

```php
'oneclick' => [
    'active' => [
        'value' => false,
        'filter' => FILTER_VALIDATE_BOOLEAN,
    ],
    
    'button_text' => [
        'value' => '⚡ Купить в один клик',
        'filter' => FILTER_SANITIZE_STRING,
    ],
    
    'clear_temp_carts' => [
        'value' => true,
        'filter' => FILTER_VALIDATE_BOOLEAN,
    ],
    
    'temp_cart_lifetime' => [
        'value' => 3600, // 1 час в секундах
        'filter' => FILTER_VALIDATE_INT,
    ],
],
```

### UI настроек

```
┌─────────────────────────────────────────────────────┐
│ ☑ Включить "Покупку в один клик"                    │
├─────────────────────────────────────────────────────┤
│ Текст кнопки:                                        │
│ [⚡ Купить в один клик                              ]│
│                                                      │
│ ☑ Автоматически очищать временные корзины           │
│                                                      │
│ Время жизни временной корзины:                       │
│ [3600] секунд (1 час)                               │
│                                                      │
│ ℹ️  Временные корзины создаются для изоляции        │
│    one-click покупок от основной корзины.            │
└─────────────────────────────────────────────────────┘
```

---

## Очистка временных корзин

### Проблема

Временные корзины остаются в БД в `shop_cart_items`. Нужен механизм очистки.

### Решение 1: Очистка при восстановлении (простое)

```php
// В хуке orderActionCreate или контроллере cancelOneClick
if ($temp_code) {
    $temp_cart = new shopCart($temp_code);
    $temp_cart->clear(); // Удаляет все записи с этим code
}
```

**Плюсы:** Простота  
**Минусы:** Если пользователь закрыл вкладку — корзина осталась в БД

### Решение 2: Cron задача (надежное)

**Путь:** `lib/cli/shopPrefillPluginCleanupCarts.cli.php`

```php
class shopPrefillPluginCleanupCartsCli extends waCliController
{
    public function execute()
    {
        $settings = shopPrefillPluginHelper::getStorefrontSettings();
        
        if (!$settings['oneclick']['active']) {
            return;
        }
        
        $lifetime = $settings['oneclick']['temp_cart_lifetime'];
        $threshold = date('Y-m-d H:i:s', time() - $lifetime);
        
        // Удаляем старые временные корзины
        $model = new shopCartItemsModel();
        
        // Найти code корзин, которые:
        // 1. Не привязаны к постоянному пользователю (contact_id = NULL или 0)
        // 2. Старше $threshold
        $sql = "DELETE FROM shop_cart_items 
                WHERE create_datetime < s:threshold 
                AND (contact_id IS NULL OR contact_id = 0)";
        
        $deleted = $model->exec($sql, ['threshold' => $threshold]);
        
        $this->log("Cleaned up {$deleted} temporary cart items");
    }
}
```

**Запуск через crontab:**

```bash
*/10 * * * * php /path/to/wa-dev/cli.php shop prefillPluginCleanupCarts
```

### Решение 3: Маркировка временных корзин

Добавить флаг в session при создании:

```php
wa()->getStorage()->set('shop/oneclick_temp_codes', [
    $temp_code => time()
]);

// При cleanup проверять только эти code
```

**Минус:** Если session истек — потеряли информацию о временных корзинах.

---

## Обработка edge cases

### 1. Пользователь закрыл вкладку без заказа

**Проблема:** Временная корзина осталась в БД, основная корзина не восстановлена.

**Решение:**
- Cron задача очищает старые временные корзины
- При следующем визите cookie `shop_cart` будет перезаписан (при создании новой корзины)

### 2. Пользователь открыл несколько вкладок

**Сценарий:**
- Вкладка 1: Основная корзина (code_A)
- Вкладка 2: One-Click (code_B)

**Что происходит:**
- Cookie `shop_cart` перезаписывается на `code_B` (one-click)
- Вкладка 1 теперь тоже видит `code_B`

**Решение:** Принимается как ограничение. Можно добавить предупреждение:

```javascript
// При инициации one-click
if (document.hidden) {
    // Вкладка не активна — предупредить
    confirm('У вас открыто несколько вкладок. Продолжить?');
}
```

### 3. Ошибка при оформлении заказа

**Проблема:** Заказ не создан, но хук `order_action.create` не сработал.

**Решение:** Добавить timeout для автоматического восстановления:

```javascript
// При переходе на checkout
setTimeout(function() {
    // Если через 10 минут все еще в oneclick_mode — что-то пошло не так
    // Восстановить корзину автоматически?
}, 600000);
```

Или проверять флаг `oneclick_mode` при любой загрузке страницы и показывать кнопку "Восстановить корзину".

### 4. Товар закончился во время оформления

**Проблема:** Пользователь нажал "Купить в один клик", но к моменту оформления товар стал unavailable.

**Решение:** Стандартная валидация Shop-Script на checkout сработает и покажет ошибку. Пользователь может нажать "Отменить" и вернуться.

---

## Метрики и аналитика

### События для отслеживания

1. `prefill_oneclick_initiated` — Клик на кнопку
2. `prefill_oneclick_checkout_opened` — Переход на checkout
3. `prefill_oneclick_order_created` — Заказ оформлен
4. `prefill_oneclick_cancelled` — Пользователь отменил

```php
// В контроллере
wa('shop')->event('prefill_oneclick_initiated', [
    'product_id' => $product_id,
    'sku_id' => $sku_id,
]);
```

### Конверсия

Отслеживать:
- Сколько раз нажата кнопка "Купить в один клик"
- Сколько заказов оформлено через one-click
- **CR = Заказы / Клики**

Сравнить с обычной корзиной для оценки эффективности.

---

## План реализации

### Фаза 1: MVP

1. [ ] Контроллер `shopPrefillPluginFrontendOneClick.controller.php`
2. [ ] Контроллер `shopPrefillPluginFrontendCancelOneClick.controller.php`
3. [ ] Хук `order_action.create` для восстановления корзины
4. [ ] JavaScript для кнопки `.js-one-click-buy`
5. [ ] Routing для контроллеров
6. [ ] Базовое тестирование (товар → checkout → заказ → восстановление)

### Фаза 2: UI и настройки

1. [ ] Настройки плагина: `oneclick` секция
2. [ ] UI для включения/отключения функции
3. [ ] Настройка текста кнопки
4. [ ] Кнопка "Отменить" на checkout
5. [ ] Стили для кнопок

### Фаза 3: Надежность

1. [ ] Cron задача для очистки временных корзин
2. [ ] Обработка edge cases (несколько вкладок, ошибки)
3. [ ] Логирование (интеграция с `shopPrefillPluginLog`)
4. [ ] Настройка времени жизни временных корзин

### Фаза 4: Аналитика

1. [ ] События для отслеживания метрик
2. [ ] Дашборд в админке (опционально)
3. [ ] Интеграция с Google Analytics / Яндекс.Метрика

---

## Связанные документы

- [CONCEPT.md](CONCEPT.md) — основная концепция плагина
- [COLLAPSIBLE-SECTIONS.md](COLLAPSIBLE-SECTIONS.md) — компактный режим checkout
- [TODO.md](../TODO.md) — задачи для реализации

---

## Открытые вопросы

1. **Интеграция с темами:** Как добавить кнопку "Купить в один клик" в разные темы?
   - Хук на странице товара?
   - Документация для разработчиков тем?

2. **Мобильная версия:** Нужны ли отличия в UX для мобильных устройств?

3. **Доступность:** Тестирование для screen readers и keyboard navigation.

4. **Локализация:** Переводы для разных языков (`locale/`).

5. **Конфликты с другими плагинами:** Тестирование совместимости с плагинами корзины.

---

## Выводы

Реализация "Покупки в один клик" через **временную корзину** — оптимальное решение:

1. ✅ Простая архитектура
2. ✅ Безопасность (изоляция корзин)
3. ✅ Совместимость с prefill и compact mode
4. ✅ Настоящий "один клик" для пользователя

**Синергия с плагином Prefill:**
- Предзаполнение + Компактный режим + One-Click = **Идеальный UX для повторных покупок**
- Конверсия может вырасти на 50%+ (по данным исследований one-click покупок)

---

**Готово к реализации!** 🚀
