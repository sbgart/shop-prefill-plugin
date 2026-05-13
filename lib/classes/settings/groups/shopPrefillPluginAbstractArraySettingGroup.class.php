<?php

abstract class shopPrefillPluginAbstractArraySettingGroup extends shopPrefillPluginSettingGroup
{
    abstract public function validateKey($key): bool;

    public function validate($settings): array
    {
        if (!is_array($settings)) {
            return [];
        }

        $validated = [];

        foreach ($settings as $key => $value) {
            if (!$this->validateKey($key)) {
                continue;
            }

            $validated[$key] = parent::validate($value);
        }

        return $validated;
    }
}
