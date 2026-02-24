# Кастомные шаблоны сводки для типов оплаты (Zen Mode)

## Описание
По аналогии с кастомными шаблонами для способов доставки (см. задачу по доставке), необходимо реализовать возможность задания уникальных шаблонов сводки (`summary_template`) для каждого отдельного метода оплаты (например, Наличные, Банковская карта, ЮKassa и т.д.).

## Необходимые доработки
1. **Конфигурация (`lib/config/storefront.settings.php`)**
   Добавить ключ `custom_templates` в секцию `payment` группы настроек `zen`:
   ```php
   'payment' => [
       'enabled' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
       'icon' => ['value' => ''],
       'summary_template' => ['value' => '<strong>{$payment_name}</strong><br />{$payment_description}'],
       'custom_templates' => ['value' => []], // Массив: [ payment_id => ['active' => true, 'template' => 'string'] ]
   ],
   ```

2. **Бэкенд-логика (`shopPrefillPluginZenMode.class.php`)**
   Обновить метод `getSummaryTemplate` так, чтобы для группы `payment` происходила проверка выбранного метода оплаты:
   - Получать ID выбранного метода оплаты: `$payment_id = $params['data']['payment']['id'] ?? ...`
   - Если для `$payment_id` существует настроенный кастомный шаблон с флагом `active = true`, использовать его.
   - Иначе использовать дефолтный шаблон `summary_template`.

3. **Интерфейс настроек (Backend UI)**
   Добавить UI в настройки плагина для вывода списка доступных методов оплаты и возможности включения/редактирования кастомного шаблона для каждого из них (аналогично доставке).
