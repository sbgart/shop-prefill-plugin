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
        $fill_params_array = [];
        $items = $fill_params_collection->get();

        // Ограничиваем до 5 элементов, как это делал toArray(false, 5)
        $items = array_slice($items, 0, 5);

        // Определяем текущий сценарий доставки для подсветки активной карточки
        $checkout_params = $instance->getSessionStorageProvider()->getCheckoutParams() ?: [];
        $current = $instance->getFillParamsProvider()->getFillParamsByCheckoutParams($checkout_params);

        foreach ($items as $item_obj) {
            $item_array = $item_obj->toArray();
            $item_array['is_current'] = $item_obj->isSameDeliveryOption($current);
            $fill_params_array[] = $item_array;
        }

        $this->view->assign([
            'app_id' => shopPrefillPlugin::APP_ID,
            'plugin_id' => shopPrefillPlugin::PLUGIN_ID,
            'plugin_url' => shopPrefillPlugin::getStaticUrl(),
            'fill_params_array' => $fill_params_array,
        ]);
    }

}