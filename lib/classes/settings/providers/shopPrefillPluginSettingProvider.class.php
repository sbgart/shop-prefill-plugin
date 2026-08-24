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
        $this->flattenSettings($key, $value, $groups, function ($name, $val, $g) {
            $this->model->set(self::CODE, $name, $val, $g);
        });
    }

    public function saveSettings($settings = []): void
    {
        $entries = [];
        $collect = function ($name, $val, $g) use (&$entries) {
            $entries[] = ['name' => $name, 'value' => $val, 'groups' => $g];
        };

        foreach ($settings as $name => $value) {
            $this->flattenSettings($name, $value, null, $collect);
        }

        $this->flattenSettings('update_time', time(), null, $collect);
        $this->flattenSettings('updated_by', wa()->getUser()->getId() ?? 0, null, $collect);

        $this->model->setBulk(self::CODE, $entries);

        shopPrefillPluginLog::info('Global plugin settings saved', [
            'updated_by' => wa()->getUser()->getId() ?? 'system',
        ]);
    }
}
