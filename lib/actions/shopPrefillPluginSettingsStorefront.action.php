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

        $paymentMethods = shopPrefillPluginPluginsProvider::getPaymentMethods();

        // Locale config
        waLocale::loadByDomain(array('shop', 'Prefill'));
        waSystem::pushActivePlugin('Prefill', 'shop');
        
        $this->view->assign([
            'app_id'          => shopPrefillPlugin::APP_ID,
            'plugin_id'       => shopPrefillPlugin::PLUGIN_ID,
            'name_prefix'     => $app_id.'_'.$plugin_id.'[storefront]['.$storefront_code.']',
            'storefront_code' => $storefront_code,
            'settings'        => $plugin->getStorefrontProvider()->getStorefront($storefront_code)->getSettings(),
            'payment_methods' => array_map(fn($method) => $method['name'], $paymentMethods),
        ]);
    }

}
