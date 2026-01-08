<?php

class shopPrefillPluginSettingProvider extends shopPrefillPluginAbstractSettingProvider
{
    public function __construct()
    {
        parent::__construct();

        $config = shopPrefillPlugin::getConfig('settings') ?? [];
        $this->structure = $this->buildStructure($config);
    }

    /**
     * @throws waDbException
     */
    public function getSettings(): array
    {
        $cache = new waRuntimeCache('prefill_settings');

        if ($cache->isCached()) {
            $settings = $cache->get();
        } else {
            $settings = $this->getSettingsModel()->get('-');
            $cache->set($settings);
        }

        return $this->validate($settings);
    }

    /**
     * @throws waException
     */
    public function setSetting($key, $value, $groups = null)
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (empty($groups)) {
                    $groups = [];
                }

                $this->setSetting($k, $v, array_merge($groups, [$key]));
            }
        } else {
            $this->getSettingsModel()->set('-', $key, $value, $groups);
        }
    }

    /**
     * @throws waException
     */
    public function saveSettings($settings = [])
    {
        foreach ($settings as $name => $value) {
            $this->setSetting($name, $value);
        }

        $this->setSetting('update_time', time());
        $this->setSetting('updated_by', wa()->getUser()->getId() ?? []);
    }
}
