<?php

class shopPrefillPluginSessionStorageProvider
{
    public bool $prefilled = false;
    private array $prefill_disabled = [];

    private shopPrefillPluginFillParamsProvider $params_provider;
    private ?shopPrefillPluginFillParams $fill_params = null;
    private waSessionStorage $storage;

    /**
     * @throws waException
     */
    public function __construct(array $prefill_disabled = [])
    {
        $this->storage = wa()->getStorage();
        $this->prefill_disabled = $prefill_disabled;
    }


    public function getStorage(): waSessionStorage
    {
        return $this->storage;
    }

    public function getCheckoutParams()
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
     * @throws waException
     * @throws waDbException
     */
    public function preFillCheckoutParams(shopPrefillPluginFillParams $params): void
    {
        $final_params = [];

        if ($this->prefilled) { // Если данные уже заполнены, то выходим.
            return;
        }

        $checkout_params = $this->getCheckoutParams();
        $checkout_params = is_array($checkout_params) ? $checkout_params : [];

        if (!isset($checkout_params['order']['region']['city'])) { // Если есть значение city, то параметры региона уже были заполнены ранее.
            $this->prepareRegionSectionParams($params, $final_params);
        }

        if (!isset($checkout_params['order']['shipping']['type_id'])) { // Если есть значение type_id, то параметры доставки уже были заполнены ранее.
            $this->prepareShippingSectionParams($params, $final_params);
            $this->preparePaymentSectionParams($params, $final_params);
            $this->prepareConfirmSectionParams($params, $final_params);
        }

        if (!isset($checkout_params['order']['payment']['id'])) { // Если есть значение id, то параметры оплаты уже были заполнены ранее.

        }

        if (!isset($checkout_params['order']['confirm']['comment'])) { // Если есть значение comment, то параметры уже были заполнены ранее.

        }

        if (!empty($final_params)) {
            $this->setCheckoutParams(shopPrefillPluginHelper::deepMergeArrays($checkout_params, $final_params));
        }

        $this->prefilled = true;
    }

    public function fillCheckoutParams(shopPrefillPluginFillParams $params): void
    {
        $final_params = [];

        $this->prepareRegionSectionParams($params, $final_params);
        $this->prepareShippingSectionParams($params, $final_params);
        $this->preparePaymentSectionParams($params, $final_params);
        $this->prepareConfirmSectionParams($params, $final_params);

        if (!empty($final_params)) {
            $checkout_params = $this->getCheckoutParams();
            $checkout_params = is_array($checkout_params) ? $checkout_params : [];

            $this->setCheckoutParams(shopPrefillPluginHelper::deepMergeArrays($checkout_params, $final_params));
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
        $final_params['order']['region']['region'] = $fill_params->getRegion();
        $final_params['order']['region']['city'] = $fill_params->getCity();
        $final_params['order']['region']['zip'] = $fill_params->getZip();
    }

    private function prepareShippingSectionParams(?shopPrefillPluginFillParams $fill_params, array &$final_params): void
    {
        if (($this->prefill_disabled['section']['shipping'] ?? false)) {
            return;
        }

        if ($fill_params === null) { //Если параметров для предзаполнения не нашли, то выходим.
            return;
        }

        $final_params['order']['shipping']['type_id'] = $fill_params->getShippingTypeId();
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

        if (!($this->prefill_disabled['fields']['comment'] ?? false)) {
            $final_params['order']['confirm']['comment'] = $fill_params->getComment();
        }
    }

}
