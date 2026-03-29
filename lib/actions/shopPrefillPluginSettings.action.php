<?php
/** @noinspection ALL */

class shopPrefillPluginSettingsAction extends waViewAction
{
    /**
     * @throws waException
     * @throws waDbException
     */
    public function execute()
    {
        waLocale::loadByDomain(['shop', 'prefill']);
        waSystem::pushActivePlugin('prefill', 'shop');

        $plugin = shopPrefillPlugin::getInstance();
        $paymentMethods = shopPrefillPluginPluginsProvider::getPaymentMethods();
        $storefronts = $plugin->getStorefrontProvider()->getStorefronts();

        $this->view->assign([
            'app_id'          => shopPrefillPlugin::APP_ID,
            'plugin_id'       => shopPrefillPlugin::PLUGIN_ID,
            'plugin_url'      => $plugin->getPluginStaticUrl(true),
            'plugin_version'  => $plugin->getVersion(),
            'settings'        => $plugin->getSettingProvider()->getSettings(),
            'storefronts'     => $storefronts->getTree(),
            'storefronts_json'=> $storefronts->toJson(),
            'payment_methods' => array_map(fn($method) => $method['name'], $paymentMethods),
        ]);
    }
}
