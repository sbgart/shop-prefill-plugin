<?php

class shopPrefillPluginFrontendParamsChoiceAction extends waViewAction
{

    /**
     * @throws waException
     */
    public function execute()
    {
        $instance = shopPrefillPlugin::getInstance();
        $fill_params_collection = $instance->getFillParamsProvider()->getFillParamsCollection();
        $fill_params_array = $fill_params_collection->toArray(false, 5);

        // Определяем текущий сценарий доставки для подсветки активной карточки
        $checkout_params = $instance->getSessionStorageProvider()->getCheckoutParams() ?: [];
        $current = $instance->getFillParamsProvider()->getFillParamsByCheckoutParams($checkout_params);

        foreach ($fill_params_array as &$item) {
            $item['is_current'] =
                $item['country'] === $current->getCountry() &&
                $item['region'] === $current->getRegion() &&
                $item['city'] === $current->getCity() &&
                $item['zip'] === $current->getZip() &&
                $item['street'] === $current->getStreet() &&
                $item['shipping_type_id'] == $current->getShippingTypeId() &&
                $item['shipping_rate_id'] === $current->getShippingRateId();
        }
        unset($item);

        $this->view->assign([
            'app_id' => shopPrefillPlugin::APP_ID,
            'plugin_id' => shopPrefillPlugin::PLUGIN_ID,
            'plugin_url' => shopPrefillPlugin::getStaticUrl(),
            'fill_params_array' => $fill_params_array,
        ]);
    }

}