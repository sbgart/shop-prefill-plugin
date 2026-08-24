<?php

require_once dirname(__DIR__) . '/lib/classes/settings/shopPrefillPluginOrphanedGroupsFilter.class.php';

/**
 * issue-80#4: zen.groups.{delivery,payment}.custom_templates.<id> не удалялись после удаления
 * инстанса доставки/оплаты — UI рисует только существующие методы, поэтому пропавший id никогда
 * не приходит в POST и штатный save() его не перезаписывает. Тесты покрывают разбор пути groups,
 * которым определяется, что считать осиротевшим.
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

$prefix = ['zen', 'groups', 'delivery', 'custom_templates'];

// ---------------------------------------------------------------------------
// 1. id вне keep_keys — осиротевшая запись, id внутри keep_keys — живая
// ---------------------------------------------------------------------------

$rows = [
    ['id' => 1, 'groups' => json_encode([...$prefix, '10'])], // жив
    ['id' => 2, 'groups' => json_encode([...$prefix, '10'])], // 'active' и 'template' одного инстанса
    ['id' => 3, 'groups' => json_encode([...$prefix, '99'])], // инстанс удалён
];

assertSameValue([3], shopPrefillPluginOrphanedGroupsFilter::filter($rows, $prefix, ['10']), 'осиротевший id вычищается, живой остаётся');

// ---------------------------------------------------------------------------
// 2. Пустой keep_keys — все инстансы под префиксом считаются осиротевшими
// ---------------------------------------------------------------------------

assertSameValue([1, 2, 3], shopPrefillPluginOrphanedGroupsFilter::filter($rows, $prefix, []), 'пустой список живых id — под щёткой всё под префиксом');

// ---------------------------------------------------------------------------
// 3. Строки с другим префиксом (например payment) не трогаются
// ---------------------------------------------------------------------------

$mixed = [
    ['id' => 1, 'groups' => json_encode([...$prefix, '99'])],                                    // delivery, осиротел
    ['id' => 2, 'groups' => json_encode(['zen', 'groups', 'payment', 'custom_templates', '99'])], // payment, чужой префикс
];

assertSameValue([1], shopPrefillPluginOrphanedGroupsFilter::filter($mixed, $prefix, []), 'фильтр не трогает строки с другим groups-префиксом');

// ---------------------------------------------------------------------------
// 4. groups короче префикса, null, пустая строка — пропускаются, а не падают
// ---------------------------------------------------------------------------

$edge = [
    ['id' => 1, 'groups' => json_encode(['zen', 'groups'])], // короче префикса
    ['id' => 2, 'groups' => null],
    ['id' => 3, 'groups' => ''],
    ['id' => 4, 'groups' => json_encode([...$prefix, '99'])], // единственный настоящий сирота
];

assertSameValue([4], shopPrefillPluginOrphanedGroupsFilter::filter($edge, $prefix, []), 'короткий путь и пустой groups игнорируются, не считаются сиротами');

// ---------------------------------------------------------------------------
// 5. keep_keys устойчив к типу: array_keys() от {id => ...} отдаёт int (PHP приводит числовые
//    строковые ключи массива к int), groups из json_decode() — всегда строка. Расхождение типов
//    не должно превращать живой инстанс в сироту.
// ---------------------------------------------------------------------------

$row = [['id' => 1, 'groups' => json_encode([...$prefix, '10'])]];

assertSameValue([], shopPrefillPluginOrphanedGroupsFilter::filter($row, $prefix, ['10']), 'keep_keys строкой — id считается живым');
assertSameValue([], shopPrefillPluginOrphanedGroupsFilter::filter($row, $prefix, [10]), 'keep_keys числом — тоже считается живым, сравнение приводит оба к строке');

echo "OrphanedGroupsFilterTest: OK\n";
