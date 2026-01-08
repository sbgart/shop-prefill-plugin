<?php

class shopPrefillPluginSettingGroup
{
    protected string $name;
    protected array $setting_fields;

    public function __construct(string $name, array $setting_fields)
    {
        $this->name = $name;
        $this->setting_fields = $setting_fields;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue($setting_values): array
    {
        $setting = [];
        foreach ($this->setting_fields as $setting_field) {
            $name = $setting_field->getName();
            $value = $setting_field->getValue($setting_values[$name] ?? null);

            $setting[$name] = $value;
        }

        return $setting;
    }
}
