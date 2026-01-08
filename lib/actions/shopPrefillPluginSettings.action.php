<?php
/** @noinspection ALL */

class shopPrefillPluginSettingsAction extends waViewAction
{
    /**
     * @throws waException
     */
    public function execute()
    {
        $plugin = shopPrefillPlugin::getInstance();

        $this->view->assign([
            'app_id'      => shopPrefillPlugin::APP_ID,
            'plugin_id'   => shopPrefillPlugin::PLUGIN_ID,
            'settings'    => $plugin->getSettingProvider()->getSettings(),
            'storefronts' => $plugin->getStorefrontProvider()->getStorefronts()->getTree(),
        ]);
    }
}
