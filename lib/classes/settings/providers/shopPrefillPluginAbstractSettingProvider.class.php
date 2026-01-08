<?php

abstract class shopPrefillPluginAbstractSettingProvider
{

    protected ?shopPrefillPluginSettingsModel $settings_model = null;
    protected array $structure = [];

    public function __construct()
    {
    }

    protected function getSettingsModel(): shopPrefillPluginSettingsModel
    {
        return $this->settings_model ??= new shopPrefillPluginSettingsModel();
    }

    protected function field($name, $default_value, $filter = FILTER_DEFAULT): shopPrefillPluginSettingField
    {
        return new shopPrefillPluginSettingField($name, $default_value, $filter);
    }

    protected function group($name, $fields): shopPrefillPluginSettingGroup
    {
        return new shopPrefillPluginSettingGroup($name, $fields);
    }

    protected function validate($settings): array
    {
        return (new shopPrefillPluginSettingGroup('settings', $this->structure))->getValue($settings);
    }

    protected function buildStructure(array $config_fields): array
    {
        $structure = [];
        foreach ($config_fields as $key => $value) {
            if (is_array($value) && isset($value['value'])) {
                $structure[] = $this->field($key, $value['value'], $value['filter'] ?? FILTER_DEFAULT);
            } elseif (is_array($value)) {
                $structure[] = $this->group($key, $this->buildStructure($value));
            }
        }

        return $structure;
    }

}
