<?php

class shopPrefillPluginSettingField
{
    protected $default_value;
    protected int $filter;

    public function __construct($default_value, int $filter = FILTER_DEFAULT)
    {
        $this->default_value = $default_value;
        $this->filter        = $filter;
    }

    public function validate($value)
    {
        if (!isset($value)) {
            return $this->default_value;
        }

        // Empty string is not a valid boolean value — return default
        if ($this->filter === FILTER_VALIDATE_BOOLEAN && $value === '') {
            return $this->default_value;
        }

        // Arrays pass through without filtering
        if (is_array($value)) {
            return $value;
        }

        return filter_var($value, $this->filter);
    }
}
