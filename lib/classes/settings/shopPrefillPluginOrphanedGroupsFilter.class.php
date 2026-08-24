<?php

/**
 * Отбирает строки shop_prefill_settings, чей путь groups лежит под заданным префиксом,
 * но следующий сегмент пути (id инстанса) не входит в список ныне существующих —
 * то, что осталось от удалённых способов доставки/оплаты (issue-80#4).
 *
 * Вынесена из shopPrefillPluginSettingsModel как чистая логика без обращений к БД,
 * чтобы разбор пути groups покрывался юнит-тестом без поднятия waModel.
 */
class shopPrefillPluginOrphanedGroupsFilter
{
    /**
     * @param array    $rows          строки {id, groups}; groups — json-строка пути или null/''
     * @param string[] $groups_prefix путь групп, под которым ищутся осиротевшие записи
     * @param string[] $keep_keys     значения следующего сегмента пути, которые считаются живыми
     * @return int[] id строк, подлежащих удалению
     */
    public static function filter(array $rows, array $groups_prefix, array $keep_keys): array
    {
        $prefix_len = count($groups_prefix);
        // PHP приводит числовые строковые ключи массива к int (array_keys() от id => ... отдаёт int),
        // а сегмент пути из json_decode() всегда строка — сравнение ниже должно быть устойчиво к обоим случаям.
        $keep_keys = array_map('strval', $keep_keys);
        $ids = [];

        foreach ($rows as $row) {
            $groups = !empty($row['groups']) ? json_decode($row['groups']) : null;

            if (!is_array($groups) || count($groups) <= $prefix_len) {
                continue;
            }

            if (array_slice($groups, 0, $prefix_len) !== $groups_prefix) {
                continue;
            }

            if (!in_array((string) $groups[$prefix_len], $keep_keys, true)) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }
}
