<?php

/**
 * Адаптер над массивом $params checkout-хука Webasyst.
 *
 * Инкапсулирует всю логику поиска данных в многомерном $params:
 * двойные ??, ifset(), приоритеты источников.
 *
 * Предоставляет строгие типизированные геттеры и единственный сеттер — applyPrefillInput().
 *
 * Конструктор принимает $params по ссылке, потому что applyPrefillInput()
 * должен менять исходный массив, который Webasyst продолжает использовать в processAll().
 */
class shopPrefillCheckoutState
{
    private array $params;
    private bool $is_prefilled = false;

    /**
     * @param array $params Массив параметров из checkout-хука (передаётся по ссылке)
     */
    public function __construct(array &$params)
    {
        $this->params = &$params;
    }

    // -------------------------------------------------------------------------
    // Контакт
    // -------------------------------------------------------------------------

    /**
     * Возвращает имя покупателя.
     * Приоритет: vars.auth.fields → data.input.auth.data
     */
    public function getFirstName(): string
    {
        return $this->params['vars']['auth']['fields']['firstname']['value']
            ?? $this->params['data']['input']['auth']['data']['firstname']
            ?? '';
    }

    /**
     * Возвращает фамилию покупателя.
     */
    public function getLastName(): string
    {
        return $this->params['vars']['auth']['fields']['lastname']['value']
            ?? $this->params['data']['input']['auth']['data']['lastname']
            ?? '';
    }

    /**
     * Возвращает телефон покупателя.
     */
    public function getPhone(): string
    {
        return $this->params['vars']['auth']['fields']['phone']['value']
            ?? $this->params['data']['input']['auth']['data']['phone']
            ?? '';
    }

    /**
     * Возвращает email покупателя.
     */
    public function getEmail(): string
    {
        return $this->params['vars']['auth']['fields']['email']['value']
            ?? $this->params['data']['input']['auth']['data']['email']
            ?? '';
    }

    /**
     * Возвращает компанию покупателя.
     */
    public function getCompany(): string
    {
        return $this->params['vars']['auth']['fields']['company']['value']
            ?? $this->params['data']['input']['auth']['data']['company']
            ?? '';
    }

    /**
     * Возвращает значение чекбокса «Согласие на обработку персональных данных» (auth[service_agreement]).
     * Для вывода в саммари Zen Mode. 1 = согласен, 0 = не согласен, '' = не задано/чекбокс отключён.
     *
     * @return int|string 1, 0 или ''
     */
    public function getServiceAgreement()
    {
        $value = $this->params['vars']['auth']['service_agreement'] ?? null;
        if ($value === null || $value === '') {
            return '';
        }
        return $value === 1 || $value === '1' ? 1 : 0;
    }

    /**
     * Возвращает текст подсказки согласия на обработку персональных данных (data[customer][service_agreement_hint]).
     * Для вывода в саммари Zen Mode. Приоритет: data.customer → vars.config → shopCheckoutConfig.
     *
     * @return string
     */
    public function getServiceAgreementHint(): string
    {
        $hint = $this->params['data']['customer']['service_agreement_hint'] ?? null;
        if ($hint !== null && $hint !== '') {
            return is_string($hint) ? $hint : '';
        }

        $config = $this->params['vars']['config'] ?? null;
        if ($config === null && class_exists('shopCheckoutConfig')) {
            try {
                $config = new shopCheckoutConfig(true);
            } catch (Exception $e) {
                return '';
            }
        }
        $hint = $config['customer']['service_agreement_hint'] ?? '';
        return is_string($hint) ? $hint : '';
    }

    /**
     * Возвращает кастомные поля контакта (всё, кроме стандартных).
     *
     * @return array<string, mixed>
     */
    public function getCustomContactFields(): array
    {
        $standard_fields = ['firstname', 'lastname', 'phone', 'email', 'company', 'password', 'confirm_password'];

        $auth_input = $this->params['data']['input']['auth']['data'] ?? [];
        $auth_fields = $this->params['vars']['auth']['fields'] ?? [];

        $custom = [];

        foreach ($auth_input as $key => $value) {
            if (!in_array($key, $standard_fields, true)) {
                $custom[$key] = $value;
            }
        }

        foreach ($auth_fields as $key => $field_data) {
            if (!in_array($key, $standard_fields, true) && !isset($custom[$key])) {
                $custom[$key] = $field_data['value'] ?? '';
            }
        }

        return $custom;
    }

    // -------------------------------------------------------------------------
    // Доставка — вариант
    // -------------------------------------------------------------------------

    /**
     * Возвращает сырой массив выбранного варианта доставки.
     * Приоритет: data.shipping.selected_variant → vars.shipping.shipping_rate
     *
     * @return array<string, mixed>
     */
    public function getSelectedVariant(): array
    {
        return $this->params['data']['shipping']['selected_variant']
            ?? $this->params['vars']['shipping']['shipping_rate']
            ?? [];
    }

    /**
     * Возвращает variant_id выбранного тарифа (формат "plugin_id.method_id").
     */
    public function getShippingVariantId(): ?string
    {
        $variant = $this->getSelectedVariant();
        $variant_id = $variant['variant_id'] ?? null;
        return ($variant_id !== null && $variant_id !== '') ? (string) $variant_id : null;
    }

    /**
     * Возвращает ID плагина/службы доставки (первая часть variant_id до точки).
     */
    public function getShippingServiceId(): ?string
    {
        $variant_id = $this->getShippingVariantId();
        if ($variant_id === null) {
            return null;
        }
        $parts = explode('.', $variant_id);
        return $parts[0] !== '' ? $parts[0] : null;
    }

    /**
     * Возвращает ID инстанса способа доставки в смысле shop_plugin.id (первая часть variant_id до точки).
     *
     * В ядре: shopCheckoutDetailsStep после успешного расчёта выставляет data.shipping.id = первая часть
     * selected_variant.variant_id; до шага details этого ключа нет. В vars результата шага shipping лежит
     * selected_variant_id (строка), а не вложенный selected_variant — см. shopCheckoutShippingStep::process().
     */
    public function getShippingInstanceId(): ?string
    {
        $id = $this->params['data']['shipping']['id'] ?? null;
        if ($id !== null && $id !== '') {
            return (string) $id;
        }

        $explicit = $this->params['data']['shipping']['selected_variant']['id'] ?? null;
        if ($explicit !== null && $explicit !== '') {
            return (string) $explicit;
        }

        $from_variant = $this->getShippingServiceId();
        if ($from_variant !== null) {
            return $from_variant;
        }

        $selected_variant_id = $this->params['vars']['shipping']['selected_variant_id'] ?? null;
        if ($selected_variant_id !== null && $selected_variant_id !== '') {
            $parts = explode('.', (string) $selected_variant_id, 2);

            return $parts[0] !== '' ? $parts[0] : null;
        }

        return null;
    }

    /**
     * Возвращает название тарифа доставки.
     */
    public function getShippingName(): string
    {
        $variant = $this->getSelectedVariant();
        return $variant['name'] ?? '';
    }

    /**
     * Возвращает стоимость доставки (или null если не задана).
     */
    public function getShippingRate(): ?float
    {
        $variant = $this->getSelectedVariant();
        $rate = $variant['rate'] ?? null;
        return ($rate !== null) ? (float) $rate : null;
    }

    /**
     * Возвращает тип доставки (pickup / courier / todoor / post).
     */
    public function getShippingType(): string
    {
        $variant = $this->getSelectedVariant();
        return $variant['type'] ?? '';
    }

    /**
     * Возвращает ориентировочный срок доставки.
     */
    public function getShippingEstDelivery(): string
    {
        $variant = $this->getSelectedVariant();
        return $variant['est_delivery'] ?? '';
    }

    /**
     * Возвращает описание варианта доставки.
     * Fallback: ищет description в custom_data.
     */
    public function getShippingDescription(): string
    {
        $variant = $this->getSelectedVariant();
        $description = $variant['description'] ?? '';

        if ($description === '') {
            $custom_data = $variant['custom_data'] ?? [];
            foreach ($custom_data as $type_data) {
                if (is_array($type_data) && !empty($type_data['description'])) {
                    $description = $type_data['description'];
                    break;
                }
            }
        }

        return $description;
    }

    /**
     * Возвращает имя плагина доставки (plugin_name).
     */
    public function getShippingPluginName(): string
    {
        $variant = $this->getSelectedVariant();
        return $variant['plugin_name'] ?? '';
    }

    /**
     * Возвращает название тарифа/сервиса доставки (service).
     */
    public function getShippingService(): string
    {
        $variant = $this->getSelectedVariant();
        return $variant['service'] ?? '';
    }

    /**
     * Возвращает инструкцию "как добраться" (way).
     */
    public function getShippingWay(): string
    {
        $service_data = $this->getFirstCustomData();
        return $service_data['way'] ?? '';
    }

    /**
     * Возвращает количество дней хранения заказа.
     */
    public function getShippingStorageDays(): string
    {
        $service_data = $this->getFirstCustomData();
        return (string) ($service_data['storage']['storage_days'] ?? '');
    }

    /**
     * Возвращает фотографии пункта выдачи.
     *
     * @return array<int, mixed>
     */
    public function getShippingPhotos(): array
    {
        $service_data = $this->getFirstCustomData();
        $photos = $service_data['photos'] ?? [];
        return is_array($photos) ? $photos : [];
    }

    /**
     * Возвращает HTML расписания пункта выдачи.
     *
     * Поддерживает два формата, которые генерирует ядро Shop-Script:
     * - pickup_schedule.days (массив) — современный формат (CDEK и др.); рендерится в .wa-day-wrapper элементы
     * - pickup_schedule_html (строка) — устаревший формат от плагинов, возвращается как есть
     *
     * Возвращает внутреннее содержимое (без внешней обёртки .wa-schedule-wrapper).
     */
    public function getShippingScheduleHtml(): string
    {
        $variant = $this->getSelectedVariant();

        $schedule = $variant['pickup_schedule'] ?? [];
        if (!empty($schedule['days']) && is_array($schedule['days'])) {
            return $this->renderPickupScheduleDays($schedule['days']);
        }

        return (string)($variant['pickup_schedule_html'] ?? '');
    }

    /**
     * Возвращает адрес пункта выдачи из custom_data[type]['description'].
     * Отдельно от getShippingDescription() — всегда даёт адрес ПВЗ,
     * а не общее описание метода доставки.
     */
    public function getShippingPickupAddress(): string
    {
        $service_data = $this->getFirstCustomData();
        return (string)($service_data['description'] ?? '');
    }

    /**
     * Рендерит структурированное расписание (pickup_schedule.days) в HTML.
     * Формат вывода совпадает с тем, что генерирует details.html для этого же источника.
     *
     * @param array $days Массив дней из pickup_schedule.days
     * @return string HTML: набор .wa-day-wrapper элементов
     */
    private function renderPickupScheduleDays(array $days): string
    {
        $locale = substr((string)wa()->getLocale(), 0, 2);
        $items = '';

        foreach ($days as $day) {
            if (!is_array($day)) {
                continue;
            }

            $date = htmlspecialchars((string)($day['date_formatted'] ?? ''), ENT_QUOTES, 'UTF-8');
            $wday = (string)($day['weekday_full'] ?? '');
            if ($locale === 'ru') {
                $wday = mb_strtolower($wday, 'UTF-8');
            }
            $wday = htmlspecialchars($wday, ENT_QUOTES, 'UTF-8');

            if (!empty($day['works'])) {
                $start = htmlspecialchars((string)($day['time_start'] ?? ''), ENT_QUOTES, 'UTF-8');
                $end   = htmlspecialchars((string)($day['time_end'] ?? ''), ENT_QUOTES, 'UTF-8');
                $value = '<span class="wa-time">' . $start . '—' . $end . '</span>';
            } else {
                $value = '<span class="wa-text">' . htmlspecialchars(_w('day off'), ENT_QUOTES, 'UTF-8') . '</span>';
            }

            $items .= '<div class="wa-day-wrapper">'
                . '<div class="wa-date">' . $date . ($wday !== '' ? ', ' . $wday : '') . '</div>'
                . '<div class="wa-value">' . $value . '</div>'
                . '</div>';
        }

        return $items;
    }

    /**
     * Возвращает кастомные поля доставки (data.shipping.custom).
     *
     * @return array<string, mixed>
     */
    public function getShippingCustomFields(): array
    {
        return $this->params['data']['shipping']['custom'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Адрес
    // -------------------------------------------------------------------------

    /**
     * Возвращает город доставки.
     */
    public function getCity(): string
    {
        return $this->findAddressField('city');
    }

    /**
     * Возвращает регион доставки (код или название).
     */
    public function getRegion(): string
    {
        return $this->params['data']['shipping']['address']['region']
            ?? $this->params['data']['input']['region']['region']
            ?? $this->params['vars']['region']['selected_values']['region_id']
            ?? '';
    }

    /**
     * Возвращает почтовый индекс.
     */
    public function getZip(): string
    {
        return $this->findAddressField('zip');
    }

    /**
     * Возвращает улицу.
     */
    public function getStreet(): string
    {
        return $this->findAddressField('street');
    }

    /**
     * Возвращает номер дома/строения.
     */
    public function getBuilding(): string
    {
        return $this->findAddressField('building');
    }

    /**
     * Возвращает номер квартиры/офиса.
     */
    public function getApartment(): string
    {
        return $this->findAddressField('apartment');
    }

    /**
     * Возвращает кастомные поля адреса (всё, кроме стандартных).
     *
     * @return array<string, mixed>
     */
    public function getCustomAddressFields(): array
    {
        $standard = ['city', 'region', 'zip', 'street', 'building', 'apartment', 'country', 'lat', 'lng'];

        $shipping_address = $this->params['data']['shipping']['address'] ?? [];
        $details_address = $this->params['data']['input']['details']['shipping_address'] ?? [];

        $custom = [];

        foreach ($shipping_address as $k => $v) {
            if (!in_array($k, $standard, true)) {
                $custom[$k] = $v;
            }
        }

        foreach ($details_address as $k => $v) {
            if (!in_array($k, $standard, true) && !isset($custom[$k])) {
                $custom[$k] = $v;
            }
        }

        return $custom;
    }

    // -------------------------------------------------------------------------
    // Оплата
    // -------------------------------------------------------------------------

    /**
     * Возвращает ID выбранного метода оплаты.
     */
    public function getPaymentId(): string
    {
        return (string) ($this->params['data']['payment']['id'] ?? '');
    }

    /**
     * Возвращает название метода оплаты.
     * Ищет сначала в vars.payment.methods, затем через PluginsProvider.
     */
    public function getPaymentName(): string
    {
        $payment_id = $this->getPaymentId();
        if ($payment_id === '') {
            return '';
        }

        $payment_methods = $this->params['vars']['payment']['methods'] ?? [];
        if (isset($payment_methods[$payment_id])) {
            return $payment_methods[$payment_id]['name'] ?? '';
        }

        $all_payments = shopPrefillPluginPluginsProvider::getPaymentMethods();
        return $all_payments[$payment_id]['name'] ?? '';
    }

    /**
     * Возвращает описание метода оплаты.
     */
    public function getPaymentDescription(): string
    {
        $payment_id = $this->getPaymentId();
        if ($payment_id === '') {
            return '';
        }

        $payment_methods = $this->params['vars']['payment']['methods'] ?? [];
        if (isset($payment_methods[$payment_id])) {
            return $payment_methods[$payment_id]['description'] ?? '';
        }

        $all_payments = shopPrefillPluginPluginsProvider::getPaymentMethods();
        return $all_payments[$payment_id]['description'] ?? '';
    }

    /**
     * Возвращает URL логотипа/иконки выбранного метода доставки.
     * Данные берутся из selected_variant (data.shipping.selected_variant или vars.shipping.shipping_rate).
     * Приоритет: logo → img → icon[48] → icon[24] → icon[16].
     *
     * @return string|null URL логотипа или null если недоступен
     */
    public function getShippingLogoUrl(): ?string
    {
        $variant = $this->getSelectedVariant();
        if (empty($variant)) {
            return null;
        }

        // logo — кастомный из БД (shop_plugin.logo), img — дефолтный из конфига плагина (= icon[48])
        return ($variant['logo'] ?: $variant['img']) ?: null;
    }

    /**
     * Возвращает URL логотипа выбранного метода оплаты.
     * Приоритет: logo → img из $params['vars']['payment']['methods'].
     *
     * @return string|null URL логотипа или null если недоступен
     */
    public function getPaymentLogoUrl(): ?string
    {
        $payment_id = $this->getPaymentId();
        if ($payment_id === '') {
            return null;
        }

        $methods = $this->params['vars']['payment']['methods'] ?? [];
        if (!isset($methods[$payment_id])) {
            return null;
        }

        $method = $methods[$payment_id];
        return $method['logo'] ?? $method['img'] ?? null;
    }

    /**
     * Возвращает кастомные поля оплаты.
     * Приоритет: data.input.payment.custom → data.payment.custom
     *
     * @return array<string, mixed>
     */
    public function getCustomPaymentFields(): array
    {
        return $this->params['data']['input']['payment']['custom']
            ?? $this->params['data']['payment']['custom']
            ?? [];
    }

    // -------------------------------------------------------------------------
    // Ошибки
    // -------------------------------------------------------------------------

    /**
     * Возвращает delayed_errors для указанного шага.
     *
     * @param string $step Имя шага: auth, details, shipping, payment, region
     * @return array<int|string, mixed>
     */
    public function getDelayedErrors(string $step): array
    {
        return $this->params['data'][$step]['delayed_errors'] ?? [];
    }

    /**
     * Возвращает обычные (критические) ошибки. Исключает маркер fast_render (Shop-Script).
     *
     * @return array<int|string, mixed>
     */
    public function getRegularErrors(): array
    {
        $errors = $this->params['errors'] ?? [];

        // Shop-Script при fast_render добавляет в errors элемент ['fast_render' => true] —
        // это не ошибка валидации, а служебный маркер ответа шага доставки.
        $is_fast_render_sentinel = static function ($item): bool {
            return is_array($item)
                && count($item) === 1
                && array_key_exists('fast_render', $item);
        };

        $real_errors = array_filter($errors, fn($e) => !$is_fast_render_sentinel($e));

        return array_values($real_errors);
    }

    /**
     * Возвращает ID шага, на котором произошла ошибка.
     */
    public function getErrorStepId(): ?string
    {
        $val = $this->params['error_step_id'] ?? null;
        return ($val !== null && $val !== '') ? (string) $val : null;
    }

    /**
     * Проверяет, не установлен ли чекбокс service_agreement.
     * Значение 0 = пользователь НЕ согласился.
     */
    public function hasServiceAgreementError(): bool
    {
        $value = $this->params['vars']['auth']['service_agreement'] ?? null;
        if ($value === null) {
            return false;
        }

        $checkout_config = $this->params['vars']['config'] ?? null;

        // В некоторых хуках (например, checkout_render_auth) ядро не прокидывает $checkout_config
        if ($checkout_config === null && class_exists('shopCheckoutConfig')) {
            try {
                $checkout_config = new shopCheckoutConfig(true);
            } catch (Exception $e) {
                // Игнорируем ошибки (например, если нет нужных параметров роутинга)
            }
        }

        if ($checkout_config !== null) {
            // ВАЖНО: Мы должны убедиться, что администратор включил чекбокс.
            // Если настройка отключена или стоит режим 'notice' (просто текст),
            // то ошибки чекбокса быть не может.
            $agreement = $checkout_config['customer']['service_agreement'] ?? null;
            if ($agreement !== 'checkbox') {
                return false;
            }
        }

        // Ошибка только если опция включена, но пользователь явно не согласился (значение 0)
        return $value === 0 || $value === '0';
    }

    /**
     * Проверяет наличие ошибок в группе.
     *
     * @param string $group customer | delivery | payment
     */
    public function hasGroupErrors(string $group): bool
    {
        return $this->getGroupErrorsInfo($group)['has_errors'];
    }

    /**
     * Возвращает структурированную информацию об ошибках группы.
     * Совместима по формату с прежним extractGroupErrors() в ZenMode.
     *
     * @param string $group customer | delivery | payment
     * @return array{has_errors: bool, group: string, errors: array<string, mixed>}
     */
    public function getGroupErrorsInfo(string $group): array
    {
        $has_errors = false;
        $group_errors = [];

        switch ($group) {
            case 'customer':
                $auth_delayed = $this->getDelayedErrors('auth');
                if (!empty($auth_delayed)) {
                    $has_errors = true;
                    $group_errors['auth_delayed_errors'] = $auth_delayed;
                }

                if ($this->hasServiceAgreementError()) {
                    $has_errors = true;
                    $group_errors['service_agreement_error'] = true;
                }

                if ($this->getErrorStepId() === 'auth') {
                    $regular = $this->getRegularErrors();
                    if (!empty($regular)) {
                        $has_errors = true;
                        $group_errors['regular_errors'] = $regular;
                    }
                }
                break;

            case 'delivery':
                $details_delayed = $this->getDelayedErrors('details');
                if (!empty($details_delayed)) {
                    $has_errors = true;
                    $group_errors['details_delayed_errors'] = $details_delayed;
                }

                if (in_array($this->getErrorStepId(), ['region', 'shipping', 'details'], true)) {
                    $regular = $this->getRegularErrors();
                    if (!empty($regular)) {
                        $has_errors = true;
                        $group_errors['regular_errors'] = $regular;
                    }
                }
                break;

            case 'payment':
                if ($this->getErrorStepId() === 'payment') {
                    $regular = $this->getRegularErrors();
                    if (!empty($regular)) {
                        $has_errors = true;
                        $group_errors['regular_errors'] = $regular;
                    }
                }
                break;
        }

        return [
            'has_errors' => $has_errors,
            'group' => $group,
            'errors' => $group_errors,
        ];
    }

    /**
     * Возвращает полный массив ошибок для debug-панели.
     * Совместим по формату с прежним extractCheckoutErrors() в CheckoutHooks.
     *
     * @return array{has_errors: bool, regular_errors: array, auth_delayed_errors: array, details_delayed_errors: array, service_agreement_error: bool, error_step_id: string|null}
     */
    public function getAllErrorsInfo(): array
    {
        $auth_delayed = $this->getDelayedErrors('auth');
        $details_delayed = $this->getDelayedErrors('details');
        $regular_errors = $this->getRegularErrors();
        $service_agreement_error = $this->hasServiceAgreementError();

        $all_delayed = array_merge($auth_delayed, $details_delayed);
        $has_errors = !empty($all_delayed) || !empty($regular_errors) || $service_agreement_error;

        return [
            'has_errors' => $has_errors,
            'regular_errors' => $regular_errors,
            'auth_delayed_errors' => $auth_delayed,
            'details_delayed_errors' => $details_delayed,
            'service_agreement_error' => $service_agreement_error,
            'error_step_id' => $this->getErrorStepId(),
        ];
    }

    // -------------------------------------------------------------------------
    // Debug / снапшот
    // -------------------------------------------------------------------------

    /**
     * Возвращает $params['data'] для debug / снапшота.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->params['data'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Единственный сеттер — мутация prefill
    // -------------------------------------------------------------------------

    /**
     * Применяет prefill-данные к params['data']['input'].
     * Пишет изменения обратно в исходный $params по ссылке.
     *
     * @param array<string, mixed> $filled_order Данные из preFillCheckoutParams()
     */
    public function applyPrefillInput(array $filled_order): void
    {
        if (empty($filled_order) || !isset($this->params['data']['input'])) {
            return;
        }

        $this->params['data']['input'] = shopPrefillPluginHelper::deepMergeArrays(
            $this->params['data']['input'],
            $filled_order
        );

        $this->is_prefilled = true;
    }

    /**
     * Возвращает true, если applyPrefillInput() был вызван и что-то изменил.
     */
    public function isPrefilled(): bool
    {
        return $this->is_prefilled;
    }

    // -------------------------------------------------------------------------
    // Приватные вспомогательные методы
    // -------------------------------------------------------------------------

    /**
     * Ищет значение поля адреса по нескольким источникам в порядке приоритета:
     * data.shipping.address → data.input.details.shipping_address → data.input.region → vars.region.selected_values
     */
    private function findAddressField(string $field): string
    {
        return $this->params['data']['shipping']['address'][$field]
            ?? $this->params['data']['input']['details']['shipping_address'][$field]
            ?? $this->params['data']['input']['region'][$field]
            ?? $this->params['vars']['region']['selected_values'][$field]
            ?? '';
    }

    /**
     * Возвращает первый элемент custom_data выбранного варианта доставки.
     * Используется для полей way, storage_days, photos.
     *
     * @return array<string, mixed>
     */
    private function getFirstCustomData(): array
    {
        $variant = $this->getSelectedVariant();
        $custom_data = $variant['custom_data'] ?? [];

        if (!empty($custom_data)) {
            $first = reset($custom_data);
            return is_array($first) ? $first : [];
        }

        return [];
    }
}
