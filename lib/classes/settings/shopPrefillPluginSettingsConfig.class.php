<?php

class shopPrefillPluginSettingsConfig
{
    private array $config;
    private ?shopPrefillPluginSettingGroup $schema = null;

    public function __construct(array $config)
    {
        $this->config = $config;
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
        return [$name => new shopPrefillPluginSettingGroup($this->parse($fields))];
    }

    public static function create(string $config_name): self
    {
        $config = shopPrefillPlugin::getConfig($config_name) ?? [];

        return new self($config);
    }
}
