<?php

class shopPrefillPluginSessionStorageProvider
{
    public bool $prefilled = false;

    private array                            $storefront_settings;
    private waSessionStorage                 $storage;
    private ?shopPrefillPluginSectionChecker $section_checker     = null;

    /**
     * @throws waException
     */
    public function __construct(array $storefront_settings = [])
    {
        $this->storage             = wa()->getStorage();
        $this->storefront_settings = $storefront_settings;
    }

    /**
     * Возвращает SectionChecker для проверки возможности предзаполнения
     */
    public function getSectionChecker(): shopPrefillPluginSectionChecker
    {
        return $this->section_checker ??= new shopPrefillPluginSectionChecker(
            $this->storefront_settings['prefill']['sections'] ?? []
        );
    }

    public function getStorage(): waSessionStorage
    {
        return $this->storage;
    }

    /**
     * Получает параметры checkout из хранилища
     *
     * @return array|null Параметры checkout или null если хранилище пустое
     */
    public function getCheckoutParams(): ?array
    {
        return $this->getStorage()->get('shop/checkout');
    }

    public function setCheckoutParams($params): bool
    {
        try {
            $this->getStorage()->set('shop/checkout', $params);

            return true;
        } catch (waException $e) {
            return false;
        }
    }

    /**
     * Заполняет параметры checkout с проверкой через SectionChecker
     *
     * @param shopPrefillPluginFillParams $params Параметры для предзаполнения
     * @throws waException
     * @throws waDbException
     */
    public function preFillCheckoutParams(shopPrefillPluginFillParams $params): void
    {
        if ($this->prefilled) {
            return;
        }

        $checkout_params = $this->getCheckoutParams();
        $checkout_params = is_array($checkout_params) ? $checkout_params : [];

        // Проверка флага first_prefill_done - не предзаполняем повторно
        if ($checkout_params['prefill_metadata']['first_prefill_done'] ?? false) {
            $this->prefilled = true;
            return;
        }

        $final_params = [];
        $checker      = $this->getSectionChecker();

        // Каждая секция проверяется НЕЗАВИСИМО
        if ($checker->canPrefillSection('auth', $checkout_params)) {
            $this->prepareAuthSectionParams($params, $final_params);
        }

        if ($checker->canPrefillSection('region', $checkout_params)) {
            $this->prepareRegionSectionParams($params, $final_params);
        }

        if ($checker->canPrefillSection('shipping', $checkout_params)) {
            $this->prepareShippingSectionParams($params, $final_params);
        }

        if ($checker->canPrefillSection('details', $checkout_params)) {
            $this->prepareDetailsSectionParams($params, $final_params);
        }

        if ($checker->canPrefillSection('payment', $checkout_params)) {
            $this->preparePaymentSectionParams($params, $final_params);
        }

        if ($checker->canPrefillSection('confirm', $checkout_params)) {
            $this->prepareConfirmSectionParams($params, $final_params);
        }

        if (! empty($final_params)) {
            // Устанавливаем флаг первого предзаполнения
            $final_params['prefill_metadata']['first_prefill_done'] = true;

            $this->setCheckoutParams(shopPrefillPluginHelper::deepMergeArrays($checkout_params, $final_params));
        }

        $this->prefilled = true;
    }

    /**
     * Подготавливает параметры auth секции для предзаполнения
     *
     * Предзаполняет только для неавторизованных пользователей:
     * - Тип покупателя (auth[mode])
     * - Поля контакта (auth[data][*])
     */
    private function prepareAuthSectionParams(?shopPrefillPluginFillParams $fill_params, array &$final_params): void
    {
        if ($fill_params === null) {
            return;
        }

        // Для авторизованных пользователей auth данные берутся из контакта автоматически
        if ($this->isUserAuthenticated()) {
            return;
        }

        // Тип покупателя (person/company)
        $customer_type = $fill_params->getCustomerType();
        if ($customer_type) {
            $final_params['order']['auth']['mode'] = $customer_type;
        }

        // Поля auth[data] (email, phone, кастомные поля)
        $auth_data = $fill_params->getAuthData();
        foreach ($auth_data as $field_id => $value) {
            $final_params['order']['auth']['data'][$field_id] = $value;
        }
    }

    /**
     * Проверяет, авторизован ли текущий пользователь
     */
    private function isUserAuthenticated(): bool
    {
        try {
            return wa()->getUser()->isAuth();
        } catch (waException $e) {
            return false;
        }
    }

    private function prepareRegionSectionParams(?shopPrefillPluginFillParams $fill_params, array &$final_params): void
    {
        if ($fill_params === null) {
            return;
        }

        $final_params['order']['region']['country'] = $fill_params->getCountry();
        $final_params['order']['region']['region']  = $fill_params->getRegion();
        $final_params['order']['region']['city']    = $fill_params->getCity();
        $final_params['order']['region']['zip']     = $fill_params->getZip();
    }

    private function prepareShippingSectionParams(?shopPrefillPluginFillParams $fill_params, array &$final_params): void
    {
        if ($fill_params === null) {
            return;
        }

        $final_params['order']['shipping']['type_id']    = $fill_params->getShippingTypeId();
        $final_params['order']['shipping']['variant_id'] = $fill_params->getShippingVariantId();

        if ($fill_params->getShippingCustom()) {
            foreach ($fill_params->getShippingCustom() as $param => $value) {
                $final_params['order']['details']['custom'][$param] = $value;
            }
        }
    }

    /**
     * Подготавливает параметры details секции (адрес доставки)
     */
    private function prepareDetailsSectionParams(?shopPrefillPluginFillParams $fill_params, array &$final_params): void
    {
        if ($fill_params === null) {
            return;
        }

        $street = $fill_params->getStreet();
        if ($street) {
            $final_params['order']['details']['shipping_address']['street'] = $street;
        }
    }

    private function preparePaymentSectionParams(?shopPrefillPluginFillParams $fill_params, array &$final_params): void
    {
        if ($fill_params === null) {
            return;
        }

        $final_params['order']['payment']['id'] = $fill_params->getPaymentId();
        if ($fill_params->getPaymentCustom()) {
            foreach ($fill_params->getPaymentCustom() as $param => $value) {
                $final_params['order']['payment']['custom'][$param] = $value;
            }
        }
    }

    private function prepareConfirmSectionParams(?shopPrefillPluginFillParams $fill_params, array &$final_params): void
    {
        if ($fill_params === null) {
            return;
        }

        $comment = $fill_params->getComment();
        if ($comment !== null) {
            $final_params['order']['confirm']['comment'] = $comment;
        }
    }

    /**
     * Очищает форму, сбрасывает флаг first_prefill_done и заново предзаполняет
     * Используется для debug кнопки "Reset & Refill"
     *
     * @param shopPrefillPluginFillParams $params Параметры для предзаполнения
     * @return void
     * @throws waException
     * @throws waDbException
     */
    public function resetAndRefill(shopPrefillPluginFillParams $params): void
    {
        // Шаг 1: Очищаем всё хранилище checkout через внедренную зависимость
        $this->getStorage()->remove('shop/checkout');

        // Шаг 2: Сбрасываем флаг prefilled (для текущего запроса)
        $this->prefilled = false;

        // Шаг 3: Заново предзаполняем (флаг first_prefill_done отсутствует)
        $this->preFillCheckoutParams($params);
    }

    /**
     * Сбрасывает только флаг first_prefill_done в метаданных
     *
     * @return bool
     */
    public function resetFirstPrefillDoneFlag(): bool
    {
        $checkout_params = $this->getCheckoutParams();
        if (! is_array($checkout_params)) {
            return false;
        }

        if (isset($checkout_params['prefill_metadata']['first_prefill_done'])) {
            unset($checkout_params['prefill_metadata']['first_prefill_done']);
            return $this->setCheckoutParams($checkout_params);
        }

        return true;
    }
}
