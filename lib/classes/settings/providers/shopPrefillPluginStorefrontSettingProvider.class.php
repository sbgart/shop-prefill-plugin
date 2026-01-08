<?php

class shopPrefillPluginStorefrontSettingProvider extends shopPrefillPluginAbstractSettingProvider
{

    /**
     * @throws waException
     */
    public function __construct()
    {
        parent::__construct();

        $config = shopPrefillPlugin::getConfig('storefront.settings') ?? [];
        $this->structure = $this->buildStructure($config);
    }

    /**
     * @throws waDbException
     */
    public function getSettings($storefront_code): array
    {
        $cache = new waRuntimeCache('prefill_settings_' . $storefront_code);
        if ($cache->isCached()) {
            $settings = $cache->get();
        } else {
            $settings = $this->getSettingsModel()->get($storefront_code);
            $cache->set($settings);
        }

        return $this->validate($settings);
    }

    /**
     * @throws waException
     */
    public function setSetting($storefront_code, $key, $value, $groups = null)
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (empty($groups)) {
                    $groups = [];
                }

                $this->setSetting($storefront_code, $k, $v, array_merge($groups, [$key]));
            }
        } else {
            $this->getSettingsModel()->set($storefront_code, $key, $value, $groups);
        }
    }

    /**
     * @throws waException
     */
    public function saveSettings($storefront_code, $settings = [])
    {
        foreach ($settings as $key => $value) {
            $this->setSetting($storefront_code, $key, $value);
        }

        $this->setSetting($storefront_code, 'update_time', time());
        $this->setSetting($storefront_code, 'updated_by', wa()->getUser()->getId() ?? []);
    }
}
