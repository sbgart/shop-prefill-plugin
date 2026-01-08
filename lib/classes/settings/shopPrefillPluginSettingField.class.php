<?php

class shopPrefillPluginSettingField
{
    protected string $name;
    protected $default_value;
    protected $filter;

    public function __construct($name, $default_value, $filter = FILTER_DEFAULT)
    {
        $this->name = $name;
        $this->default_value = $default_value;
        $this->filter = $filter;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue($setting_value)
    {
        return isset($setting_value) ? filter_var($setting_value, $this->filter) : $this->default_value;
    }
}
