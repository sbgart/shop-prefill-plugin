<?php

require_once dirname(__DIR__) . '/lib/classes/settings/shopPrefillPluginSettingsBulkWritePlanner.class.php';

/**
 * issue-74#5: SettingsModel::set() дёргал SELECT+INSERT/UPDATE на каждый лист дерева настроек —
 * сотня-другая запросов на одно сохранение формы. Планировщик делит список листьев на "вставить" /
 * "обновить" по уже загруженным существующим строкам, без обращений к БД внутри самой логики.
 *
 * @param mixed  $expected
 * @param mixed  $actual
 * @param string $message
 */
function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

// ---------------------------------------------------------------------------
// 1. Нет существующих строк — всё уходит в to_insert, to_update пуст
// ---------------------------------------------------------------------------

$entries = [
    ['name' => 'active', 'value' => 1, 'groups' => null],
    ['name' => 'accent_color', 'value' => '#000', 'groups' => ['styles']],
];

$plan = shopPrefillPluginSettingsBulkWritePlanner::plan([], $entries);

assertSameValue([], $plan['to_update'], 'без существующих строк обновлять нечего');
assertSameValue(
    [
        ['name' => 'active', 'groups' => 'null', 'value' => 1],
        ['name' => 'accent_color', 'groups' => '["styles"]', 'value' => '#000'],
    ],
    $plan['to_insert'],
    'все листья уходят во вставку, groups закодирован в JSON как в БД'
);

// ---------------------------------------------------------------------------
// 2. Существующая строка с тем же (name, groups) — обновление по её id, не вставка
// ---------------------------------------------------------------------------

$existing = [
    ['id' => 10, 'name' => 'active', 'groups' => 'null'],
    ['id' => 11, 'name' => 'accent_color', 'groups' => '["styles"]'],
];

$plan = shopPrefillPluginSettingsBulkWritePlanner::plan($existing, $entries);

assertSameValue([], $plan['to_insert'], 'обе строки уже есть в БД — вставлять нечего');
assertSameValue([10 => 1, 11 => '#000'], $plan['to_update'], 'значения матчатся на существующие id по (name, groups)');

// ---------------------------------------------------------------------------
// 3. Смешанный случай: часть листьев уже есть, часть — новые (например, custom_templates
//    для только что добавленного способа доставки)
// ---------------------------------------------------------------------------

$mixed_entries = [
    ['name' => 'active', 'value' => 0, 'groups' => null],
    ['name' => 'template', 'value' => 'foo', 'groups' => ['zen', 'groups', 'delivery', 'custom_templates', '42']],
];

$plan = shopPrefillPluginSettingsBulkWritePlanner::plan(
    [['id' => 10, 'name' => 'active', 'groups' => 'null']],
    $mixed_entries
);

assertSameValue([10 => 0], $plan['to_update'], 'active матчится на существующий id');
assertSameValue(
    [['name' => 'template', 'groups' => '["zen","groups","delivery","custom_templates","42"]', 'value' => 'foo']],
    $plan['to_insert'],
    'новый лист под ранее не существовавшим groups-путём уходит во вставку'
);

// ---------------------------------------------------------------------------
// 4. Одинаковый (name, groups) дважды в entries — последнее значение побеждает
//    (как и раньше побеждал последний вызов set())
// ---------------------------------------------------------------------------

$duplicate_entries = [
    ['name' => 'level', 'value' => 'warning', 'groups' => ['logging']],
    ['name' => 'level', 'value' => 'debug', 'groups' => ['logging']],
];

$plan = shopPrefillPluginSettingsBulkWritePlanner::plan(
    [['id' => 5, 'name' => 'level', 'groups' => '["logging"]']],
    $duplicate_entries
);

assertSameValue([5 => 'debug'], $plan['to_update'], 'повтор листа — побеждает последнее значение в списке');

echo "SettingsBulkWritePlannerTest: OK\n";
