<?php

/**
 * Класс для подготовки данных для Zen Mode шаблонов
 * Отвечает за извлечение данных из params чекаута и формирование переменных для Smarty.
 */
class shopPrefillPluginZenData
{
    /**
     * Определяет, какие группы полей (из getAvailableFields) доступны в редакторе/превью
     * для каждого блока Zen Mode.
     *
     * Единственный источник правды для:
     * - правой колонки переменных в модалке
     * - превью шаблона (какие переменные заполняем)
     */
    public const TEMPLATE_EDITOR_FIELD_GROUPS = [
        'customer' => ['contact'],
        'delivery' => ['delivery', 'address', 'contact'],
        'payment'  => ['payment', 'contact'],
    ];

    /**
     * Иммутабельные шаблоны по умолчанию для каждой группы.
     * Используются как стартовая точка при первой активации кастомного шаблона
     * и как цель кнопки «Сбросить к стандартному» в модальном окне редактора.
     *
     * ВАЖНО: строки намеренно дублируются в lib/config/storefront.settings.php (значения 'value').
     * Менять здесь — значит менять factory defaults для сброса; менять в storefront.settings.php —
     * значит менять defaults для новых установок плагина.
     */
    private const DEFAULT_SUMMARY_TEMPLATES = [
        'customer' => '{if $company}{$company} • {/if}{$firstname} {$lastname} • {$phone}',
        'delivery' => '<strong>{$delivery_plugin}</strong><br />{$shipping_name} • {$shipping_rate}',
        'payment'  => '<strong>{$payment_name}</strong><br />{$payment_description}',
    ];

    /**
     * Возвращает иммутабельный шаблон по умолчанию для группы.
     * Используется для кнопки «Сбросить к стандартному» в UI редактора кастомного шаблона.
     *
     * @param string $group customer|delivery|payment
     * @return string
     */
    public static function getDefaultTemplate(string $group): string
    {
        return self::DEFAULT_SUMMARY_TEMPLATES[$group] ?? '';
    }

    private waView $view;
    private string $currency;

    public function __construct(waView $view = null)
    {
        $this->view = $view ?? wa()->getView();
        $this->currency = wa('shop')->getConfig()->getCurrency();
    }

    /**
     * Возвращает список всех доступных полей для Smarty-шаблонов.
     * Служит единственным источником правды — используется как для рендера данных,
     * так и для построения UI модального редактора (переменные + подсказки).
     *
     * Поле 'group' определяет принадлежность поля к секции:
     *   'contact'  — данные покупателя
     *   'delivery' — данные доставки
     *   'address'  — данные адреса
     *   'payment'  — данные оплаты
     *
     * @return array<string, array{
     *     name: string,
     *     description: string,
     *     example: string,
     *     group: string,
     *     is_array?: bool,
     *     snippet_loop?: string,
     *     example_code?: string
     * }>
     */
    public static function getAvailableFields(): array
    {
        // snippet_loop: готовые фрагменты Smarty; массивы приходят из shopPrefillCheckoutState как ассоциативные
        // (custom fields) либо список хэшей (photos — uri/thumb_uri в элементах).
        return [
            // === КОНТАКТ ===
            'firstname' => [
                'group' => 'contact',
                'name' => _wp('First name'),
                'description' => _wp('Customer first name'),
                'example' => _wp('zen.custom_template.example_value.firstname'),
            ],
            'lastname' => [
                'group' => 'contact',
                'name' => _wp('Last name'),
                'description' => _wp('Customer last name'),
                'example' => _wp('zen.custom_template.example_value.lastname'),
            ],
            'phone' => [
                'group' => 'contact',
                'name' => _wp('Phone'),
                'description' => _wp('Customer phone number'),
                'example' => _wp('zen.custom_template.example_value.phone'),
            ],
            'email' => [
                'group' => 'contact',
                'name' => _wp('Email'),
                'description' => _wp('Customer email'),
                'example' => _wp('zen.custom_template.example_value.email'),
            ],
            'company' => [
                'group' => 'contact',
                'name' => _wp('Company'),
                'description' => _wp('Company name'),
                'example' => _wp('zen.custom_template.example_value.company'),
            ],
            'contact_custom' => [
                'group' => 'contact',
                'name' => _wp('Custom contact fields'),
                'description' => _wp('Array of custom contact fields (e.g. birthday)'),
                'example' => _wp('zen.custom_template.example_value.contact_custom'),
                'is_array' => true,
                'example_code' => '{$contact_custom.birthday}',
                'snippet_loop' => '{foreach $contact_custom as $k => $v}{$k|escape}: {$v|escape}{if !$v@last} &bull; {/if}{/foreach}',
            ],
            'service_agreement' => [
                'group' => 'contact',
                'name' => _wp('Service agreement'),
                'description' => _wp('Consent to personal data processing (auth[service_agreement]). Localized Yes/No or empty.'),
                'example' => _wp('zen.service_agreement.yes'),
            ],
            'service_agreement_hint' => [
                'group' => 'contact',
                'name' => _wp('Service agreement hint'),
                'description' => _wp('Text of the consent hint (data[customer][service_agreement_hint]) from checkout config.'),
                'example' => _wp('zen.custom_template.example_value.service_agreement_hint'),
            ],

            // === ДОСТАВКА ===
            'shipping_name' => [
                'group' => 'delivery',
                'name' => _wp('Shipping rate name'),
                'description' => _wp('Selected shipping rate name'),
                'example' => _wp('zen.custom_template.example_value.shipping_name'),
            ],
            'shipping_rate' => [
                'group' => 'delivery',
                'name' => _wp('Shipping cost'),
                'description' => _wp('Formatted shipping cost (HTML)'),
                'example' => _wp('zen.custom_template.example_value.shipping_rate'),
            ],
            'delivery_method_name' => [
                'group' => 'delivery',
                'name' => _wp('Delivery method name'),
                'description' => _wp('Shipping plugin name from store DB (may be empty if not found)'),
                'example' => _wp('zen.custom_template.example_value.delivery_method_name'),
            ],
            'shipping_logo' => [
                'group' => 'delivery',
                'name' => _wp('Shipping logo URL'),
                'description' => _wp('URL of the selected shipping plugin logo (from checkout vars)'),
                'example' => _wp('zen.custom_template.example_value.url_sample'),
            ],
            'delivery_plugin' => [
                'group' => 'delivery',
                'name' => _wp('Plugin name'),
                'description' => _wp('Shipping carrier name from checkout data (always set when delivery is selected)'),
                'example' => _wp('zen.custom_template.example_value.delivery_plugin'),
            ],
            'delivery_tariff' => [
                'group' => 'delivery',
                'name' => _wp('Delivery tariff'),
                'description' => _wp('Delivery tariff/service name'),
                'example' => _wp('zen.custom_template.example_value.delivery_tariff'),
            ],
            'delivery_type' => [
                'group' => 'delivery',
                'name' => _wp('Delivery type'),
                'description' => _wp('Delivery type (e.g. pickup, courier, post)'),
                'example' => _wp('zen.delivery.type.pickup'),
            ],
            'delivery_est_delivery' => [
                'group' => 'delivery',
                'name' => _wp('Estimated delivery'),
                'description' => _wp('Estimated delivery time'),
                'example' => _wp('zen.custom_template.example_value.est_delivery'),
            ],
            'delivery_description' => [
                'group' => 'delivery',
                'name' => _wp('Description'),
                'description' => _wp('Description of the delivery method'),
                'example' => _wp('zen.custom_template.example_value.delivery_description'),
            ],
            'delivery_schedule' => [
                'group' => 'delivery',
                'name' => _wp('Business hours'),
                'description' => _wp('Pickup point business hours (HTML structure)'),
                'example' => _wp('zen.custom_template.example_value.schedule_fragment'),
            ],
            'delivery_pickup_address' => [
                'group' => 'delivery',
                'name' => _wp('Pickup point address'),
                'description' => _wp('Full address of the pickup point (from custom_data description). Plain text, not the delivery method description.'),
                'example' => _wp('zen.custom_template.example_value.delivery_pickup_address'),
            ],
            'delivery_way' => [
                'group' => 'delivery',
                'name' => _wp('Way to reach'),
                'description' => _wp('Instructions on how to reach the pickup point'),
                'example' => _wp('zen.custom_template.example_value.delivery_way'),
            ],
            'delivery_storage_days' => [
                'group' => 'delivery',
                'name' => _wp('Storage days'),
                'description' => _wp('Number of days the order is stored'),
                'example' => _wp('zen.custom_template.example_value.storage_days'),
            ],
            'delivery_photos' => [
                'group' => 'delivery',
                'name' => _wp('Photos'),
                'description' => _wp('Array of pickup point photos'),
                'example' => _wp('zen.custom_template.example_value.delivery_photos'),
                'is_array' => true,
                'example_code' => '{foreach $delivery_photos as $photo}{$photo.thumb_uri|default:$photo.uri}{/foreach}',
                'snippet_loop' => '{foreach $delivery_photos as $photo}<img src="{if !empty($photo.thumb_uri)}{$photo.thumb_uri|escape}{else}{$photo.uri|escape}{/if}" alt="" />{/foreach}',
            ],
            'delivery_photos_html' => [
                'group' => 'delivery',
                'name' => _wp('Photo gallery'),
                'description' => _wp('Native photo gallery with lightbox and horizontal scroll (HTML). Works automatically inside the delivery step.'),
                'example' => _wp('zen.custom_template.example_value.delivery_photos_html'),
            ],
            'shipping_custom' => [
                'group' => 'delivery',
                'name' => _wp('Custom shipping fields'),
                'description' => _wp('Array of custom shipping fields'),
                'example' => _wp('zen.custom_template.example_value.shipping_custom'),
                'is_array' => true,
                'example_code' => '{$shipping_custom.time_interval}',
                'snippet_loop' => '{foreach $shipping_custom as $k => $v}{$k|escape}: {$v|escape}{if !$v@last} &bull; {/if}{/foreach}',
            ],

            // === АДРЕС ===
            'city' => [
                'group' => 'address',
                'name' => _wp('City'),
                'description' => _wp('Delivery city'),
                'example' => _wp('zen.custom_template.example_value.city'),
            ],
            'region' => [
                'group' => 'address',
                'name' => _wp('Region'),
                'description' => _wp('Delivery region code or name'),
                'example' => _wp('zen.custom_template.example_value.region'),
            ],
            'street' => [
                'group' => 'address',
                'name' => _wp('Street'),
                'description' => _wp('Street address'),
                'example' => _wp('zen.custom_template.example_value.street'),
            ],
            'building' => [
                'group' => 'address',
                'name' => _wp('Building'),
                'description' => _wp('Building number'),
                'example' => _wp('zen.custom_template.example_value.building'),
            ],
            'apartment' => [
                'group' => 'address',
                'name' => _wp('Apartment'),
                'description' => _wp('Apartment/Office number'),
                'example' => _wp('zen.custom_template.example_value.apartment'),
            ],
            'zip' => [
                'group' => 'address',
                'name' => _wp('ZIP'),
                'description' => _wp('Postal code'),
                'example' => _wp('zen.custom_template.example_value.zip'),
            ],
            'address_custom' => [
                'group' => 'address',
                'name' => _wp('Custom address fields'),
                'description' => _wp('Array of custom address fields (e.g. metro)'),
                'example' => _wp('zen.custom_template.example_value.address_custom'),
                'is_array' => true,
                'example_code' => '{$address_custom.metro}',
                'snippet_loop' => '{foreach $address_custom as $k => $v}{$k|escape}: {$v|escape}{if !$v@last} &bull; {/if}{/foreach}',
            ],

            // === ОПЛАТА ===
            'payment_name' => [
                'group' => 'payment',
                'name' => _wp('Payment method'),
                'description' => _wp('Selected payment method name'),
                'example' => _wp('zen.custom_template.example_value.payment_name'),
            ],
            'payment_logo' => [
                'group' => 'payment',
                'name' => _wp('Payment logo URL'),
                'description' => _wp('URL of the selected payment plugin logo (from checkout vars)'),
                'example' => _wp('zen.custom_template.example_value.url_sample'),
            ],
            'payment_description' => [
                'group' => 'payment',
                'name' => _wp('Payment description'),
                'description' => _wp('Payment method description'),
                'example' => _wp('zen.custom_template.example_value.payment_description'),
            ],
            'payment_custom' => [
                'group' => 'payment',
                'name' => _wp('Custom payment fields'),
                'description' => _wp('Array of custom payment fields (e.g. INN, Company)'),
                'example' => _wp('zen.custom_template.example_value.payment_custom'),
                'is_array' => true,
                'example_code' => '{$payment_custom.inn}',
                'snippet_loop' => '{foreach $payment_custom as $k => $v}{$k|escape}: {$v|escape}{if !$v@last} &bull; {/if}{/foreach}',
            ],
        ];
    }

    /**
     * Тестовые данные для превью шаблона в редакторе (админка).
     * Использует example-значения из getAvailableFields(), а если example пустой —
     * подставляет визуальный плейсхолдер, чтобы было наглядно.
     *
     * @param string $group customer|delivery|payment
     * @return array
     */
    public static function getSampleData(string $group): array
    {
        $fields = self::getAvailableFields();

        $data = array_fill_keys(array_keys($fields), '');

        // Массивы инициализируем как [], а не '' — чтобы тип совпадал даже если поле не попало в спец-блоки ниже.
        foreach ($fields as $key => $field) {
            if (!empty($field['is_array'])) {
                $data[$key] = [];
            }
        }

        $allowed_groups = self::TEMPLATE_EDITOR_FIELD_GROUPS[$group] ?? [];

        foreach ($fields as $key => $field) {
            if (empty($field['group']) || !in_array($field['group'], $allowed_groups, true)) {
                continue;
            }

            if (!empty($field['is_array'])) {
                continue;
            }

            $example = isset($field['example']) ? trim((string)$field['example']) : '';
            if ($example !== '') {
                $data[$key] = $example;
                continue;
            }

            $name = isset($field['name']) ? (string)$field['name'] : $key;
            $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            $data[$key] = '<span class="prefill-ct-placeholder">[ ' . $safe_name . ' ]</span>';
        }

        // Поля, которые в реальности содержат HTML (не plain text) — оборачиваем, чтобы превью совпадало с реальным выводом.
        if ($data['shipping_rate'] !== '') {
            $safe = htmlspecialchars((string)$data['shipping_rate'], ENT_QUOTES, 'UTF-8');
            $data['shipping_rate'] = '<span class="prefill-zen-price">' . $safe . '</span>';
        }
        if ($data['delivery_schedule'] !== '') {
            $safe = htmlspecialchars((string)$data['delivery_schedule'], ENT_QUOTES, 'UTF-8');
            $data['delivery_schedule'] = '<p>' . $safe . '</p>';
        }

        // Минимальные массивы, чтобы foreach-циклы выглядели правдоподобно.
        if (in_array($group, self::groupsWithContactCustom(), true)) {
            $data['contact_custom'] = [
                'birthday' => '01.01.1990',
            ];
        }
        if ($group === 'delivery') {
            $data['shipping_custom'] = [
                'time_interval' => '10:00–18:00',
            ];
            $data['address_custom'] = [
                'metro' => 'Сокольники',
            ];
            $sample_photos = [['uri' => '#', 'thumb_uri' => '']];
            $data['delivery_photos'] = $sample_photos;
            $data['delivery_photos_html'] = self::buildPhotosHtml($sample_photos, $data['shipping_name']);
        }
        if ($group === 'payment') {
            $data['payment_custom'] = [
                'inn' => '7712345678',
            ];
        }

        return $data;
    }

    private static function groupsWithContactCustom(): array
    {
        return ['customer', 'delivery', 'payment'];
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
        $fields = self::getAvailableFields();
        $data = array_fill_keys(array_keys($fields), '');

        foreach ($fields as $key => $field) {
            if (!empty($field['is_array'])) {
                $data[$key] = [];
            }
        }

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

        $agreement = $state->getServiceAgreement();
        $data['service_agreement'] = $agreement === 1
            ? _wp('zen.service_agreement.yes')
            : ($agreement === 0 ? _wp('zen.service_agreement.no') : '');

        $data['service_agreement_hint'] = $state->getServiceAgreementHint();
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
        $data['delivery_pickup_address'] = $state->getShippingPickupAddress();
        $data['delivery_way'] = $state->getShippingWay();
        $data['delivery_storage_days'] = $state->getShippingStorageDays();
        $photos = $state->getShippingPhotos();
        $data['delivery_photos'] = $photos;
        $data['delivery_photos_html'] = self::buildPhotosHtml($photos, $data['shipping_name']);
        $data['delivery_schedule'] = $state->getShippingScheduleHtml();
        $data['shipping_custom'] = $state->getShippingCustomFields();
        $data['shipping_logo'] = $state->getShippingLogoUrl() ?? '';

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
        $data['payment_logo'] = $state->getPaymentLogoUrl() ?? '';
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
     * Генерирует нативную HTML-структуру `.wa-photos-section` для фотографий ПВЗ.
     * Совместима с Details.prototype.initPhotos() из shop/js/frontend/order/form.js —
     * лайтбокс и прокрутка инициализируются автоматически, если блок находится внутри шага details.
     *
     * @param array  $photos Массив фотографий: [['uri' => ..., 'thumb_uri' => ...], ...]
     * @param string $name   Имя тарифа (используется как заголовок в диалоге лайтбокса)
     * @return string HTML или '' если photos пуст
     */
    private static function buildPhotosHtml(array $photos, string $name): string
    {
        $items_html = '';
        foreach ($photos as $photo) {
            $uri = isset($photo['uri']) ? trim((string)$photo['uri']) : '';
            if ($uri === '') {
                continue;
            }
            $thumb_uri = !empty($photo['thumb_uri']) ? (string)$photo['thumb_uri'] : $uri;
            $uri_esc   = htmlspecialchars($uri, ENT_QUOTES, 'UTF-8');
            $thumb_esc = htmlspecialchars($thumb_uri, ENT_QUOTES, 'UTF-8');

            $items_html .= '<div class="wa-photo-wrapper" data-image-uri="' . $uri_esc . '" data-thumb-uri="' . $thumb_esc . '">'
                . '<a class="wa-photo js-show-photo" href="' . $uri_esc . '" style="background-image: url(' . $thumb_esc . ');" target="_blank"></a>'
                . '</div>';
        }

        if ($items_html === '') {
            return '';
        }

        $root_url   = wa()->getRootUrl();
        $version    = wa()->getVersion('shop');
        $sprite_url = htmlspecialchars(
            $root_url . 'wa-apps/shop/img/frontend/order/svg/sprite.svg?v=' . $version,
            ENT_QUOTES,
            'UTF-8'
        );
        $data_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        $arrow_l = '<i class="wa-icon arrow-left"><svg><use xlink:href="' . $sprite_url . '#arrow-left"></use></svg></i>';
        $arrow_r = '<i class="wa-icon arrow-right"><svg><use xlink:href="' . $sprite_url . '#arrow-right"></use></svg></i>';

        return '<div class="wa-line wa-photos-section" data-name="' . $data_name . '">'
            . '<div class="wa-action left js-scroll-prev">' . $arrow_l . '</div>'
            . '<div class="wa-photos-list">' . $items_html . '</div>'
            . '<div class="wa-action right js-scroll-next">' . $arrow_r . '</div>'
            . '</div>';
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
