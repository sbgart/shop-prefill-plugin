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

        $tt = $instance->getPluginsProvider()->getShippingMethods();

        $this->view->assign([
            'app_id'            => shopPrefillPlugin::APP_ID,
            'plugin_id'         => shopPrefillPlugin::PLUGIN_ID,
            'plugin_url'        => shopPrefillPlugin::getStaticUrl(),
            'fill_params_array' => $fill_params_array,
        ]);
    }

}