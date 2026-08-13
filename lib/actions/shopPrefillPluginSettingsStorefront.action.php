<?php
/** @noinspection PhpPossiblePolymorphicInvocationInspection */

class shopPrefillPluginSettingsStorefrontAction extends waViewAction
{
    /**
     * @throws waException
     * @throws waDbException
     */
    public function execute()
    {
        $storefront_code = waRequest::post('code');

        $app_id = shopPrefillPlugin::APP_ID;
        $plugin_id = shopPrefillPlugin::PLUGIN_ID;

        $plugin = shopPrefillPlugin::getInstance();

        $shippingMethods = shopPrefillPluginPluginsProvider::getShippingMethods();
        $paymentMethods  = shopPrefillPluginPluginsProvider::getPaymentMethods();


        // Locale config
        waLocale::loadByDomain(['shop', 'prefill']);
        waSystem::pushActivePlugin('prefill', 'shop');

        // Витрину могли удалить или переименовать после загрузки списка в браузере
        $storefront = $plugin->getStorefrontProvider()->findStorefront($storefront_code);

        if ($storefront === null) {
            throw new waException(_wp('error.storefront_not_found'), 404);
        }

        $this->view->assign([
            'app_id'               => shopPrefillPlugin::APP_ID,
            'plugin_id'            => shopPrefillPlugin::PLUGIN_ID,
            'name_prefix'          => $app_id.'_'.$plugin_id.'[storefront]['.$storefront_code.']',
            'storefront_code'      => $storefront_code,
            'settings'             => $storefront->getSettings(),
            'global_settings'      => $plugin->getSettingProvider()->getSettings(),
            'shipping_methods'     => $shippingMethods,
            'payment_methods'      => $paymentMethods,
        ]);
    }

}
