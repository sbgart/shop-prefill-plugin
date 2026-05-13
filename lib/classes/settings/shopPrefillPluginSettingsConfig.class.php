<?php

class shopPrefillPluginSettingsConfig
{
    private array $config;
    private array $setting_groups;
    private ?shopPrefillPluginSettingGroup $schema = null;

    public function __construct(array $config, array $setting_groups = [])
    {
        $this->config = $config;
        $this->setting_groups = $setting_groups;
    }

    public function getSchema(): shopPrefillPluginSettingGroup
    {
        return $this->schema ??= new shopPrefillPluginSettingGroup($this->parse($this->config));
    }

    private function parse(array $config): array
    {
        $schema = [];

        foreach ($config as $name => $options) {
            if ($this->isField($options)) {
                $schema[$name] = $this->field($options);
            } else {
                $schema = $schema + $this->group($name, $options);
            }
        }

        return $schema;
    }

    // Field: array with 'value' key, e.g. ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN]
    private function isField($value): bool
    {
        return is_array($value) && array_key_exists('value', $value);
    }

    private function field(array $options): shopPrefillPluginSettingField
    {
        return new shopPrefillPluginSettingField(
            $options['value'],
            $options['filter'] ?? FILTER_DEFAULT
        );
    }

    private function group(string $name, array $fields): array
    {
        $sub_schema = $this->parse($fields);

        // $ prefix → dynamic group class from setting_groups.php
        if (substr($name, 0, 1) === '$') {
            $group_name  = substr($name, 1);
            $group_class = $this->setting_groups[$group_name] ?? null;

            if ($group_class) {
                return [$group_name => new $group_class($sub_schema)];
            }
        }

        return [$name => new shopPrefillPluginSettingGroup($sub_schema)];
    }

    public static function create(string $config_name): self
    {
        $config = shopPrefillPlugin::getConfig($config_name) ?? [];
        $groups = shopPrefillPlugin::getConfig('setting_groups') ?? [];

        return new self($config, $groups);
    }
}
