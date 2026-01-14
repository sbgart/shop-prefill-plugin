<?php

class shopPrefillPluginSessionStorageProvider
{
    public bool   $prefilled        = false;
    private array $prefill_disabled = [];

    private shopPrefillPluginFillParamsProvider $params_provider;
    private ?shopPrefillPluginFillParams        $fill_params     = null;
    private waSessionStorage                    $storage;

    /**
     * @throws waException
     */
    public function __construct(array $prefill_disabled = [])
    {
        $this->storage          = wa()->getStorage();
        $this->prefill_disabled = $prefill_disabled;
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
     * Заполняет параметры checkout с проверкой наличия данных
     *
     * @param shopPrefillPluginFillParams $params Параметры для предзаполнения
     * @throws waException
     * @throws waDbException
     */
    public function preFillCheckoutParams(shopPrefillPluginFillParams $params): void
    {
        // Если уже заполняли в этом запросе, проверяем наличие данных перед повторным заполнением
        if ($this->prefilled) {
            return;
        }

        $final_params = [];

        $checkout_params = $this->getCheckoutParams();
        $checkout_params = is_array($checkout_params) ? $checkout_params : [];

        // Auth секция - предзаполняем если нет данных о пользователе
        if (! isset($checkout_params['order']['auth']['data']['email'])) {
            $this->prepareAuthSectionParams($params, $final_params);
        }

        if (! isset($checkout_params['order']['region']['city'])) {
            $this->prepareRegionSectionParams($params, $final_params);
        }

        if (! isset($checkout_params['order']['shipping']['type_id'])) {
            $this->prepareShippingSectionParams($params, $final_params);
            $this->preparePaymentSectionParams($params, $final_params);
            $this->prepareConfirmSectionParams($params, $final_params);
        }

        if (! empty($final_params)) {
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
        if (($this->prefill_disabled['section']['auth'] ?? false)) {
            return;
        }

        if ($fill_params === null) {
            return;
        }

        // Для авторизованных пользователей auth данные берутся из контакта автоматически
        if ($this->isUserAuthenticated()) {
            return;
        }

        // Тип покупателя (person/company)
        $customer_type = $fill_params->getCustomerType();
        if ($customer_type && ! ($this->prefill_disabled['fields']['customer_type'] ?? false)) {
            $final_params['order']['auth']['mode'] = $customer_type;
        }

        // Поля auth[data] (email, phone, кастомные поля)
        $auth_data = $fill_params->getAuthData();
        foreach ($auth_data as $field_id => $value) {
            if (! ($this->prefill_disabled['fields'][$field_id] ?? false)) {
                $final_params['order']['auth']['data'][$field_id] = $value;
            }
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
        if (($this->prefill_disabled['section']['region'] ?? false)) {
            return;
        }

        if ($fill_params === null) { //Если параметров для предзаполнения не нашли, то выходим.
            return;
        }

        $final_params['order']['region']['country'] = $fill_params->getCountry();
        $final_params['order']['region']['region']  = $fill_params->getRegion();
        $final_params['order']['region']['city']    = $fill_params->getCity();
        $final_params['order']['region']['zip']     = $fill_params->getZip();
    }

    private function prepareShippingSectionParams(?shopPrefillPluginFillParams $fill_params, array &$final_params): void
    {
        if (($this->prefill_disabled['section']['shipping'] ?? false)) {
            return;
        }

        if ($fill_params === null) { //Если параметров для предзаполнения не нашли, то выходим.
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

    private function preparePaymentSectionParams(?shopPrefillPluginFillParams $fill_params, array &$final_params): void
    {
        if (($this->prefill_disabled['section']['payment'] ?? false)) {
            return;
        }

        if ($fill_params === null) { //Если параметров для предзаполнения не нашли, то выходим.
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
        if (($this->prefill_disabled['section']['confirm'] ?? false)) {
            return;
        }

        if ($fill_params === null) { //Если параметров для предзаполнения не нашли, то выходим.
            return;
        }

        if (! ($this->prefill_disabled['fields']['comment'] ?? false)) {
            $final_params['order']['confirm']['comment'] = $fill_params->getComment();
        }
    }

}
