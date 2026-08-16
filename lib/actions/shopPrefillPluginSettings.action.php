<?php
/** @noinspection ALL */

class shopPrefillPluginSettingsAction extends shopPrefillPluginSettingsBaseAction
{
    /**
     * @throws waException
     * @throws waDbException
     */
    protected function handle()
    {
        waLocale::loadByDomain(['shop', 'prefill']);
        waSystem::pushActivePlugin('prefill', 'shop');

        $plugin = shopPrefillPlugin::getInstance();
        $paymentMethods = shopPrefillPluginPluginsProvider::getPaymentMethods();
        $storefronts = $plugin->getStorefrontProvider()->getStorefronts();
        $global_storefront = $plugin->getStorefrontProvider()->getGlobalStorefront();

        $this->view->assign([
            'app_id'          => shopPrefillPlugin::APP_ID,
            'plugin_id'       => shopPrefillPlugin::PLUGIN_ID,
            'plugin_url'      => $plugin->getPluginStaticUrl(true),
            'plugin_version'  => $plugin->getVersion(),
            'settings'        => $plugin->getSettingProvider()->getSettings(),
            'storefronts'     => $storefronts->getTree(),
            'storefronts_json'=> $storefronts->toJson($global_storefront),
            'payment_methods' => array_map(fn($method) => $method['name'], $paymentMethods),
        ]);
    }
}
