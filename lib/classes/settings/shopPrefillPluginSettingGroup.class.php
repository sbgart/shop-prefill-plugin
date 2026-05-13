<?php

class shopPrefillPluginSettingGroup
{
    // Associative: name => shopPrefillPluginSettingField|shopPrefillPluginSettingGroup
    protected array $schema = [];

    public function __construct(array $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Validate settings against schema, filling in defaults for missing keys.
     * Iterates schema (not input) so all fields are always present in output.
     *
     * @param array|mixed $settings
     */
    public function validate($settings): array
    {
        if (!is_array($settings)) {
            $settings = [];
        }

        $validated = [];

        foreach ($this->schema as $name => $field) {
            $raw              = $settings[$name] ?? null;
            $validated[$name] = $field->validate($raw);
        }

        return $validated;
    }

    /**
     * Validate a single setting by name, navigating nested groups via $groups path.
     *
     * @param string     $name
     * @param mixed      $value
     * @param array|null $groups
     * @return mixed
     */
    public function validateValue(string $name, $value, array $groups = null)
    {
        if (!empty($groups)) {
            $group = $groups[0];
            $field = $this->schema[$group] ?? null;

            if (!$field || $field instanceof shopPrefillPluginSettingField) {
                return null;
            }

            return $field->validateValue($name, $value, array_slice($groups, 1));
        }

        $field = $this->schema[$name] ?? null;

        if (!$field || !($field instanceof shopPrefillPluginSettingField)) {
            return null;
        }

        return $field->validate($value);
    }
}
