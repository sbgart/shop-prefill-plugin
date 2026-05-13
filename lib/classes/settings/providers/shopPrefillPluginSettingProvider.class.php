<?php

class shopPrefillPluginSettingProvider extends shopPrefillPluginAbstractSettingProvider
{
    // Global settings use '-' as the storefront code
    private const CODE = '-';

    public function __construct()
    {
        parent::__construct(
            new shopPrefillPluginSettingsModel(),
            shopPrefillPluginSettingsConfig::create('settings')
        );
    }

    public function getSettings(): array
    {
        return $this->validate($this->model->get(self::CODE));
    }

    public function setSetting($key, $value, $groups = null): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $this->setSetting($k, $v, array_merge((array) $groups, [$key]));
            }
            return;
        }

        // bool → int so false isn't stored as empty string
        if (is_bool($value)) {
            $value = (int) $value;
        }

        $this->model->set(self::CODE, $key, $value, $groups);
    }

    public function saveSettings($settings = []): void
    {
        foreach ($settings as $name => $value) {
            $this->setSetting($name, $value);
        }

        $this->setSetting('update_time', time());
        $this->setSetting('updated_by', wa()->getUser()->getId() ?? 0);

        shopPrefillPluginLog::info('Global plugin settings saved', [
            'updated_by' => wa()->getUser()->getId() ?? 'system',
        ]);
    }
}
