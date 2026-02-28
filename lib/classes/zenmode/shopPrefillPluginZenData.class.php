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
     * Извлекает данные для сводки из состояния чекаута
     *
     * @param string $group Имя группы
     * @param shopPrefillCheckoutState $state Адаптер параметров чекаута
     * @return array
     * @throws waException
     */
    public function extractSummaryData(string $group, shopPrefillCheckoutState $state): array
    {
        // Инициализируем массив данными по умолчанию
        $data = array_fill_keys(array_keys($this->getAvailableFields()), '');

        $this->extractContactData($state, $data);
        $this->extractDeliveryData($state, $data);
        $this->extractPaymentData($state, $data);

        return $data;
    }

    private function extractContactData(shopPrefillCheckoutState $state, array &$data): void
    {
        $data['firstname'] = $state->getFirstName();
        $data['lastname'] = $state->getLastName();
        $data['phone'] = $state->getPhone();
        $data['email'] = $state->getEmail();
        $data['company'] = $state->getCompany();
        $data['contact_custom'] = $state->getCustomContactFields();
    }

    /**
     * @throws waException
     */
    private function extractDeliveryData(shopPrefillCheckoutState $state, array &$data): void
    {
        // 1. Основные данные тарифа
        $data['shipping_name'] = $state->getShippingName();

        $rate = $state->getShippingRate();
        $data['shipping_rate'] = $rate !== null ? $this->formatPrice($rate) : '';

        // 2. Имя службы доставки (плагина)
        $service_id = $state->getShippingServiceId();
        if ($service_id !== null) {
            $shipping_methods = shopPrefillPluginPluginsProvider::getShippingMethods();
            if (isset($shipping_methods[$service_id])) {
                $data['delivery_method_name'] = $shipping_methods[$service_id]['name'];
            }
        }

        // 3. Расширенные данные доставки
        $data['delivery_est_delivery'] = $state->getShippingEstDelivery();
        $data['delivery_plugin'] = $state->getShippingPluginName();
        $data['delivery_tariff'] = $state->getShippingService();
        $data['delivery_type'] = $this->formatDeliveryType($state->getShippingType());
        $data['delivery_description'] = $state->getShippingDescription();
        $data['delivery_way'] = $state->getShippingWay();
        $data['delivery_storage_days'] = $state->getShippingStorageDays();
        $data['delivery_photos'] = $state->getShippingPhotos();
        $data['delivery_schedule'] = $state->getShippingScheduleHtml();
        $data['shipping_custom'] = $state->getShippingCustomFields();

        // 4. Адресные данные
        $data['city'] = $state->getCity();
        $data['region'] = $state->getRegion();
        $data['zip'] = $state->getZip();
        $data['street'] = $state->getStreet();
        $data['building'] = $state->getBuilding();
        $data['apartment'] = $state->getApartment();
        $data['address_custom'] = $state->getCustomAddressFields();
    }

    /**
     * @throws waException
     */
    private function extractPaymentData(shopPrefillCheckoutState $state, array &$data): void
    {
        $data['payment_name'] = $state->getPaymentName();
        $data['payment_description'] = $state->getPaymentDescription();
        $data['payment_custom'] = $state->getCustomPaymentFields();
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
