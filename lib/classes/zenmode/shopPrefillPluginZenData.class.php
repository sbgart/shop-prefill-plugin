<?php

/**
 * Класс для подготовки данных для Zen Mode шаблонов
 * Отвечает за извлечение данных из params чекаута и формирование переменных для Smarty.
 */
class shopPrefillPluginZenData
{
    private waView $view;
    private string $currency;

    public function __construct(waView $view = null)
    {
        $this->view = $view ?? wa()->getView();
        $this->currency = wa('shop')->getConfig()->getCurrency();
    }

    /**
     * Возвращает список всех доступных полей для шаблонов
     * Используется для документации и (в будущем) визуального редактора.
     *
     * @return array
     */
    public function getAvailableFields(): array
    {
        return [
            // === КОНТАКТ ===
            'firstname' => [
                'name' => _wp('First name'),
                'description' => _wp('Customer first name'),
                'example' => 'Ivan',
            ],
            'lastname' => [
                'name' => _wp('Last name'),
                'description' => _wp('Customer last name'),
                'example' => 'Ivanov',
            ],
            'phone' => [
                'name' => _wp('Phone'),
                'description' => _wp('Customer phone number'),
                'example' => '+79991234567',
            ],
            'email' => [
                'name' => _wp('Email'),
                'description' => _wp('Customer email'),
                'example' => 'ivan@example.com',
            ],
            'company' => [
                'name' => _wp('Company'),
                'description' => _wp('Company name'),
                'example' => 'Horn & Hooves',
            ],
            'contact_custom' => [
                'name' => _wp('Custom contact fields'),
                'description' => _wp('Array of custom contact fields (e.g. birthday)'),
                'example' => '{$contact_custom.birthday}',
            ],

            // === ДОСТАВКА (Основные) ===
            'shipping_name' => [
                'name' => _wp('Shipping rate name'),
                'description' => _wp('Selected shipping rate name'),
                'example' => 'Courier delivery',
            ],
            'shipping_rate' => [
                'name' => _wp('Shipping cost'),
                'description' => _wp('Formatted shipping cost'),
                'example' => '300 rub',
            ],
            'delivery_method_name' => [
                'name' => _wp('Delivery method name'),
                'description' => _wp('Name of the shipping method in store settings'),
                'example' => 'Delivery by CDEK',
            ],

            // === ДОСТАВКА (Детали) ===
            'delivery_plugin' => [
                'name' => _wp('Plugin name'),
                'description' => _wp('Delivery plugin name'),
                'example' => _wp('Pickup point'),
            ],
            'delivery_tariff' => [
                'name' => _wp('Delivery tariff'),
                'description' => _wp('Delivery tariff/service name'),
                'example' => _wp('Store pickup'),
            ],
            'delivery_type' => [
                'name' => _wp('Delivery type'),
                'description' => _wp('Delivery type (e.g. pickup, courier, post)'),
                'example' => 'pickup',
            ],
            'delivery_est_delivery' => [
                'name' => _wp('Estimated delivery'),
                'description' => _wp('Estimated delivery time'),
                'example' => '1-2 days',
            ],
            'delivery_description' => [
                'name' => _wp('Description'),
                'description' => _wp('Description of the delivery method'),
                'example' => 'Delivery to the door',
            ],
            'delivery_schedule' => [
                'name' => _wp('Business hours'),
                'description' => _wp('Pickup point business hours (HTML structure)'),
                'example' => '<div class="wa-schedule-wrapper">...</div>',
            ],
            'delivery_way' => [
                'name' => _wp('Way to reach'),
                'description' => _wp('Instructions on how to reach the pickup point'),
                'example' => 'Entrance from the yard',
            ],
            'delivery_storage_days' => [
                'name' => _wp('Storage days'),
                'description' => _wp('Number of days the order is stored'),
                'example' => '5',
            ],
            // Photos array is complex, maybe just mention it exists
            'delivery_photos' => [
                'name' => _wp('Photos'),
                'description' => _wp('Array of pickup point photos'),
                'example' => '[{"uri": "...", "thumb_uri": "..."}]',
            ],
            'shipping_custom' => [
                'name' => _wp('Custom shipping fields'),
                'description' => _wp('Array of custom shipping fields'),
                'example' => '{$shipping_custom.time_interval}',
            ],

            // === АДРЕС ===
            'city' => [
                'name' => _wp('City'),
                'description' => _wp('Delivery city'),
                'example' => 'Moscow',
            ],
            'region' => [
                'name' => _wp('Region'),
                'description' => _wp('Delivery region code or name'),
                'example' => '77',
            ],
            'street' => [
                'name' => _wp('Street'),
                'description' => _wp('Street address'),
                'example' => 'Lenina st.',
            ],
            'building' => [
                'name' => _wp('Building'),
                'description' => _wp('Building number'),
                'example' => '10',
            ],
            'apartment' => [
                'name' => _wp('Apartment'),
                'description' => _wp('Apartment/Office number'),
                'example' => '15',
            ],
            'zip' => [
                'name' => _wp('ZIP'),
                'description' => _wp('Postal code'),
                'example' => '123456',
            ],
            'address_custom' => [
                'name' => _wp('Custom address fields'),
                'description' => _wp('Array of custom address fields (e.g. metro)'),
                'example' => '{$address_custom.metro}',
            ],

            // === ОПЛАТА ===
            'payment_name' => [
                'name' => _wp('Payment method'),
                'description' => _wp('Selected payment method name'),
                'example' => 'Cash on delivery',
            ],
            'payment_description' => [
                'name' => _wp('Payment description'),
                'description' => _wp('Payment method description'),
                'example' => _wp('Payment upon receipt'),
            ],
            'payment_custom' => [
                'name' => _wp('Custom payment fields'),
                'description' => _wp('Array of custom payment fields (e.g. INN, Company)'),
                'example' => '{$payment_custom.inn}',
            ],

        ];
    }

    /**
     * Извлекает данные для сводки из params чекаута
     *
     * @param string $group Имя группы
     * @param array $params Данные чекаута
     * @return array
     * @throws waException
     */
    public function extractSummaryData(string $group, array $params): array
    {
        // Инициализируем массив данными по умолчанию (из списка доступных полей)
        $data = array_fill_keys(array_keys($this->getAvailableFields()), '');

        // Заполняем данными в зависимости от группы
        // Можно было бы разделить на разные методы, но пока оставим в одном для простоты,
        // так как некоторые данные могут пересекаться или требоваться глобально.

        $this->extractContactData($params, $data);
        $this->extractDeliveryData($params, $data); // Включая адрес
        $this->extractPaymentData($params, $data);

        return $data;
    }

    private function extractContactData(array $params, array &$data): void
    {
        // Приоритет: vars → input
        $auth_fields = $params['vars']['auth']['fields'] ?? [];
        $auth_input = $params['data']['input']['auth']['data'] ?? [];

        $data['firstname'] = $auth_fields['firstname']['value'] ?? $auth_input['firstname'] ?? '';
        $data['lastname'] = $auth_fields['lastname']['value'] ?? $auth_input['lastname'] ?? '';
        $data['phone'] = $auth_fields['phone']['value'] ?? $auth_input['phone'] ?? '';
        $data['email'] = $auth_fields['email']['value'] ?? $auth_input['email'] ?? '';
        $data['company'] = $auth_fields['company']['value'] ?? $auth_input['company'] ?? '';

        // --- Custom Contact Fields ---
        $standard_fields = ['firstname', 'lastname', 'phone', 'email', 'company', 'password', 'confirm_password'];
        $custom_fields = [];

        // Собираем все поля из инпута, исключая стандартные
        if (!empty($auth_input)) {
            foreach ($auth_input as $key => $value) {
                if (!in_array($key, $standard_fields)) {
                    $custom_fields[$key] = $value;
                }
            }
        }

        // Дополняем из fields (для отображения лейблов можно было бы использовать fields configuration, 
        // но пока просто берем значения)
        if (!empty($auth_fields)) {
            foreach ($auth_fields as $key => $field_data) {
                if (!in_array($key, $standard_fields) && !isset($custom_fields[$key])) {
                    $custom_fields[$key] = $field_data['value'] ?? '';
                }
            }
        }

        $data['contact_custom'] = $custom_fields;
    }

    /**
     * @throws waException
     */
    private function extractDeliveryData(array $params, array &$data): void
    {
        // Приоритет: data.shipping.selected_variant → vars.shipping.shipping_rate
        $selected_variant = $params['data']['shipping']['selected_variant'] ?? [];
        $shipping_rate_data = $params['vars']['shipping']['shipping_rate'] ?? [];

        // 1. Основные данные тарифа
        $data['shipping_name'] = $selected_variant['name'] ?? $shipping_rate_data['name'] ?? '';

        $shipping_rate_raw = $selected_variant['rate'] ?? $shipping_rate_data['rate'] ?? null;
        $data['shipping_rate'] = $shipping_rate_raw !== null ? $this->formatPrice($shipping_rate_raw) : '';

        // 2. Имя службы доставки (Плагина)
        $variant_id = $selected_variant['variant_id'] ?? $shipping_rate_data['variant_id'] ?? '';
        if (!empty($variant_id)) {
            // variant_id обычно имеет формат "plugin_id.method_id" или просто "plugin_id"
            $parts = explode('.', $variant_id);
            $service_id = $parts[0];

            $shipping_methods = shopPrefillPluginPluginsProvider::getShippingMethods();
            if (isset($shipping_methods[$service_id])) {
                $data['delivery_method_name'] = $shipping_methods[$service_id]['name'];
            }
        }

        // 3. Расширенные данные доставки (из custom_data или корня варианта)
        // Обычно Webasyst кладет всё самое вкусное (est_delivery, description) в корень варианта, 
        // а специфику (way, schedule) в custom_data.

        // Est Delivery
        $est_delivery = $selected_variant['est_delivery'] ?? $shipping_rate_data['est_delivery'] ?? '';
        $data['delivery_est_delivery'] = $est_delivery;

        $data['delivery_plugin'] = $selected_variant['plugin_name'] ?? $shipping_rate_data['plugin_name'] ?? '';
        $data['delivery_tariff'] = $selected_variant['service'] ?? $shipping_rate_data['service'] ?? '';

        $raw_type = $selected_variant['type'] ?? $shipping_rate_data['type'] ?? '';
        $data['delivery_type'] = $this->formatDeliveryType($raw_type);

        // Description
        $description = $selected_variant['description'] ?? $shipping_rate_data['description'] ?? '';
        // Иногда description лежит в custom_data
        if (empty($description)) {
            $custom_data = $selected_variant['custom_data'] ?? $shipping_rate_data['custom_data'] ?? [];
            // custom_data имеет структуру [service_type => [...data...]]
            // Нам нужно найти первый непустой массив или по типу сервиса, если бы мы его знали наверняка.
            // Но обычно там только один ключ с типом.
            foreach ($custom_data as $type_data) {
                if (is_array($type_data) && !empty($type_data['description'])) {
                    $description = $type_data['description'];
                    break;
                }
            }
        }
        $data['delivery_description'] = $description;

        // Custom Data Parsing (Schedule, Way, Storage, Photos)
        $custom_data = $selected_variant['custom_data'] ?? $shipping_rate_data['custom_data'] ?? [];
        // Берем данные из первого попавшегося типа сервиса (todoor, pickup, post)
        // Так как выбран только один вариант
        $service_data = null;
        if (!empty($custom_data)) {
            $service_data = reset($custom_data);
        }

        if ($service_data) {
            $data['delivery_way'] = $service_data['way'] ?? '';
            $data['delivery_storage_days'] = $service_data['storage']['storage_days'] ?? '';
            $data['delivery_photos'] = $service_data['photos'] ?? []; // Array
        }

        // Schedule (Часы работы)
        // Может быть html (pickup_schedule_html) или структурой (pickup_schedule)
        // Обычно это лежит в корне shipping_rate
        $pickup_schedule_html = $selected_variant['pickup_schedule_html'] ?? $shipping_rate_data['pickup_schedule_html'] ?? '';
        if (!empty($pickup_schedule_html)) {
            $data['delivery_schedule'] = $pickup_schedule_html;
        } else {
            // Если html нет, но есть структура дней - можно было бы сгенерировать html, 
            // но это сложная логика шаблона. Пока оставим пустым или попробуем найти pre-rendered.
            // В details.html есть логика рендера. Мы не будем её дублировать сейчас.
        }

        // --- Custom Shipping Fields ---
        // Плагины доставки могут просить доп поля, которые лежат в shipping[id][custom]
        // Но структура params сложная. Обычно:
        // $params['data']['shipping']['custom'] или внутри selected_variant?
        // Чаще всего это shipping[service_id][custom_field].
        // Попробуем найти 'custom' в корне shipping data

        $data['shipping_custom'] = $params['data']['shipping']['custom'] ?? [];


        // 4. Адресные данные
        $shipping_address = $params['data']['shipping']['address'] ?? [];
        $region_input = $params['data']['input']['region'] ?? [];
        $region_selected = $params['vars']['region']['selected_values'] ?? [];
        $details_address = $params['data']['input']['details']['shipping_address'] ?? [];

        // Объединяем источники адреса (приоритет: shipping > details > region)
        // Но для формирования финального массива address_custom нам нужно знать что есть что.
        // Проще всего взять $shipping_address как наиболее полный, если он есть.

        // Helper to find value across sources
        $findAddr = fn($k) => $shipping_address[$k] ?? $details_address[$k] ?? $region_input[$k] ?? $region_selected[$k] ?? '';

        $data['city'] = $findAddr('city');
        $data['region'] = $shipping_address['region'] ?? $region_input['region'] ?? $region_selected['region_id'] ?? '';
        $data['zip'] = $findAddr('zip');
        $data['street'] = $findAddr('street');
        $data['building'] = $findAddr('building');
        $data['apartment'] = $findAddr('apartment');

        // --- Custom Address Fields ---
        $standard_addr_fields = ['city', 'region', 'zip', 'street', 'building', 'apartment', 'country', 'lat', 'lng'];
        $custom_addr_fields = [];

        // Собираем из shipping_address (самый надежный источник после сохранения)
        foreach ($shipping_address as $k => $v) {
            if (!in_array($k, $standard_addr_fields)) {
                $custom_addr_fields[$k] = $v;
            }
        }

        // Дополняем из input details если чего-то нет
        foreach ($details_address as $k => $v) {
            if (!in_array($k, $standard_addr_fields) && !isset($custom_addr_fields[$k])) {
                $custom_addr_fields[$k] = $v;
            }
        }

        $data['address_custom'] = $custom_addr_fields;
    }

    /**
     * @throws waException
     */
    private function extractPaymentData(array $params, array &$data): void
    {
        $payment_id = $params['data']['payment']['id'] ?? '';
        if (!empty($payment_id)) {
            // Пытаемся получить имя из methods в vars
            $payment_methods = $params['vars']['payment']['methods'] ?? [];
            if (isset($payment_methods[$payment_id])) {
                $data['payment_name'] = $payment_methods[$payment_id]['name'] ?? '';
                $data['payment_description'] = $payment_methods[$payment_id]['description'] ?? '';
                // Custom fields from input or vars
                $data['payment_custom'] = $params['data']['input']['payment']['custom'] ?? $params['data']['payment']['custom'] ?? [];
                return;
            }

            // Если нет в vars, пробуем через провайдер плагинов (закэшированный)
            $all_payments = shopPrefillPluginPluginsProvider::getPaymentMethods();
            if (isset($all_payments[$payment_id])) {
                $data['payment_name'] = $all_payments[$payment_id]['name'] ?? '';
                $data['payment_description'] = $all_payments[$payment_id]['description'] ?? '';
            }
        }

    }

    /**
     * Форматирует цену для отображения в сводке
     *
     * @param float|string $price Цена
     * @return string HTML с форматированной ценой
     */
    private function formatPrice($price): string
    {
        if (empty($price) || $price == 0) {
            $free_text = _wp('zen.price.free'); // Нужно убедиться, что ключ существует или использовать дефолт
            return '<span class="prefill-zen-price-free">' . htmlspecialchars($free_text) . '</span>';
        }

        $formatted = wa_currency_html($price, $this->currency, '%t{h}');
        return '<span class="prefill-zen-price">' . $formatted . '</span>';
    }

    /**
     * Форматирует тип доставки с использованием локализации
     *
     * @param string $type Строковый тип доставки (pickup, courier, post, todoor)
     * @return string Локализованное название типа
     */
    private function formatDeliveryType(string $type): string
    {
        switch ($type) {
            case 'pickup':
                return _wp('zen.delivery.type.pickup');
            case 'todoor':
            case 'courier':
                return _wp('zen.delivery.type.courier');
            case 'post':
                return _wp('zen.delivery.type.post');
            default:
                // Если тип неизвестен, пытаемся вернуть как есть или с Заглавной буквы
                return mb_convert_case($type, MB_CASE_TITLE, 'UTF-8');
        }
    }
}
