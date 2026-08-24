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
     * Пишет весь плоский список листьев дерева настроек за 1 SELECT + максимум один
     * multi-row INSERT + один batched UPDATE, вместо SELECT+INSERT/UPDATE на каждый лист
     * (issue-74#5). Без уникального индекса и ON DUPLICATE KEY — тот подход отклонён в
     * issue-57#4 (MyISAM/utf8mb3, префиксный индекс на groups не влезает).
     *
     * @param array $entries листья {name, value, groups}; groups — путь как PHP-массив/null
     */
    public function setBulk(string $storefront_code, array $entries): void
    {
        if (!$entries) {
            return;
        }

        $sql = "SELECT id, name, `groups` FROM {$this->table} WHERE `storefront_code` = s:storefront_code";
        $existing_rows = $this->query($sql, ['storefront_code' => $storefront_code])->fetchAll();

        $plan = shopPrefillPluginSettingsBulkWritePlanner::plan($existing_rows, $entries);

        if ($plan['to_insert']) {
            $rows = array_map(static function (array $row) use ($storefront_code) {
                return $row + ['storefront_code' => $storefront_code];
            }, $plan['to_insert']);

            $this->multipleInsert($rows);
        }

        if ($plan['to_update']) {
            $this->bulkUpdateValues($plan['to_update']);
        }

        unset(self::$cache[$storefront_code]);
    }

    /**
     * UPDATE ... SET value = CASE id WHEN ... THEN ... END WHERE id IN (...) — один запрос
     * на любое число строк вместо updateByField() на каждую. id — свои же автоинкрементные
     * значения (из SELECT в setBulk()), поэтому безопасно приводить к int напрямую.
     *
     * @param array<int|string, mixed> $values_by_id id строки => новое value
     */
    private function bulkUpdateValues(array $values_by_id): void
    {
        $case_sql = 'CASE id';
        $params   = [];
        $index    = 0;

        foreach ($values_by_id as $id => $value) {
            $index++;
            $case_sql .= " WHEN i:id_{$index} THEN s:val_{$index}";
            $params["id_{$index}"] = (int) $id;
            $params["val_{$index}"] = $value;
        }

        $case_sql .= ' END';
        $params['ids'] = array_map('intval', array_keys($values_by_id));

        $sql = "UPDATE {$this->table} SET value = {$case_sql} WHERE id IN (i:ids)";
        $this->exec($sql, $params);
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
