<?php

class shopPrefillPluginSettingsModel extends waModel
{
    protected $table = 'shop_prefill_settings';

    private static array $cache = [];

    public function get(string $storefront_code = '*'): array
    {
        if (!array_key_exists($storefront_code, self::$cache)) {
            $sql = "SELECT * FROM {$this->table} WHERE `storefront_code` = s:storefront_code";
            $rows = $this->query($sql, ['storefront_code' => $storefront_code]);
            self::$cache[$storefront_code] = $this->parse($rows);
        }

        return self::$cache[$storefront_code];
    }

    public function set(string $storefront_code, string $name, $value, $groups = null): void
    {
        $fields = [
            'storefront_code' => $storefront_code,
            'name'            => $name,
            'groups'          => json_encode($groups),
        ];

        if ($this->getByField($fields)) {
            $this->updateByField($fields, ['value' => $value]);
        } else {
            $this->insert(array_merge($fields, ['value' => $value]));
        }

        unset(self::$cache[$storefront_code]);
    }

    public function clearCache(string $storefront_code): void
    {
        unset(self::$cache[$storefront_code]);
    }

    private function parse($rows): array
    {
        $settings = [];

        foreach ($rows as $row) {
            $name   = $row['name'];
            $value  = $row['value'];
            $groups = !empty($row['groups']) ? json_decode($row['groups']) : null;

            if (is_array($groups)) {
                $ptr = &$settings;
                foreach ($groups as $group) {
                    if (!isset($ptr[$group])) {
                        $ptr[$group] = [];
                    }
                    $ptr = &$ptr[$group];
                }
                $ptr[$name] = $value;
            } else {
                $settings[$name] = $value;
            }
        }

        return $settings;
    }
}
