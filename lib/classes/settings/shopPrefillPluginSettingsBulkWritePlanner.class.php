<?php

/**
 * Делит плоский список листьев дерева настроек на "вставить" / "обновить" по существующим
 * строкам shop_prefill_settings — чтобы SettingsModel::setBulk() не дёргал SELECT+INSERT/UPDATE
 * на каждый лист (issue-74#5: сотня-другая запросов на одно сохранение формы).
 *
 * Вынесена из shopPrefillPluginSettingsModel как чистая логика без обращений к БД, по тому же
 * принципу, что и shopPrefillPluginOrphanedGroupsFilter.
 */
class shopPrefillPluginSettingsBulkWritePlanner
{
    /**
     * @param array $existing_rows строки {id, name, groups} из БД; groups — json-строка пути или null
     * @param array $entries       листья дерева настроек {name, value, groups}; groups — путь как
     *                             PHP-массив/null (ещё не закодирован в JSON)
     * @return array{to_insert: array[], to_update: array<int|string, mixed>}
     *         to_insert — строки {name, groups, value} без storefront_code (добавляет вызывающий),
     *         groups уже json-encoded; to_update — [id => value]
     */
    public static function plan(array $existing_rows, array $entries): array
    {
        $existing_ids = [];
        foreach ($existing_rows as $row) {
            $existing_ids[$row['name'] . '|' . $row['groups']] = $row['id'];
        }

        $to_insert = [];
        $to_update = [];

        foreach ($entries as $entry) {
            $groups_json = json_encode($entry['groups']);
            $key         = $entry['name'] . '|' . $groups_json;

            if (isset($existing_ids[$key])) {
                // Повтор одного и того же листа в пределах одного saveSettings() — не ожидается
                // при корректном дереве настроек, но если случится, последний в списке побеждает,
                // как и раньше побеждал последний вызов set().
                $to_update[$existing_ids[$key]] = $entry['value'];
            } else {
                $to_insert[] = [
                    'name'   => $entry['name'],
                    'groups' => $groups_json,
                    'value'  => $entry['value'],
                ];
            }
        }

        return ['to_insert' => $to_insert, 'to_update' => $to_update];
    }
}
