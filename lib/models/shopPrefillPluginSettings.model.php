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

    /**
     * Удаляет строки, чей groups лежит под $groups_prefix, а следующий сегмент пути
     * (id инстанса) отсутствует в $keep_keys — то, что осталось от удалённых
     * способов доставки/оплаты и не перезаписывается штатным save (issue-80#4).
     *
     * @param string[] $groups_prefix
     * @param string[] $keep_keys
     * @return int Число удалённых строк
     */
    public function deleteOrphanedGroups(string $storefront_code, array $groups_prefix, array $keep_keys): int
    {
        $sql  = "SELECT id, `groups` FROM {$this->table} WHERE `storefront_code` = s:storefront_code";
        $rows = $this->query($sql, ['storefront_code' => $storefront_code])->fetchAll();

        $ids = shopPrefillPluginOrphanedGroupsFilter::filter($rows, $groups_prefix, $keep_keys);

        if ($ids) {
            $this->deleteById($ids);
            unset(self::$cache[$storefront_code]);
        }

        return count($ids);
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
