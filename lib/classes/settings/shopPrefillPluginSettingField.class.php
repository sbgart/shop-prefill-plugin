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
        // Для boolean фильтра: пустая строка = default value
        // Для остальных: пустая строка - валидное значение
        if (!isset($setting_value)) {
            return $this->default_value;
        }

        if ($this->filter === FILTER_VALIDATE_BOOLEAN && $setting_value === '') {
            return $this->default_value;
        }

        // filter_var() не поддерживает массивы без FILTER_FORCE_ARRAY → вернуть как есть
        if (is_array($setting_value)) {
            return $setting_value;
        }

        return filter_var($setting_value, $this->filter);
    }
}
