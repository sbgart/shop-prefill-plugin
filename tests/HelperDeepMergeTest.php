<?php

/**
 * A1 (TEST-PLAN.md §5): deepMergeArrays() не мутирует вход (регресс issue-40);
 * stripEmptyLeaves() не съедает '0' и false. Правило P9.
 *
 * Чистые статические функции без зависимости от Webasyst — require без стабов.
 *
 * Запуск: php tests/HelperDeepMergeTest.php
 */

require_once dirname(__DIR__) . '/lib/classes/helpers/shopPrefillPluginHelper.class.php';

$failures = 0;
$checks   = 0;

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function check($expected, $actual, string $message): void
{
    global $failures, $checks;
    $checks++;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$message}\n";
        echo '  expected: ' . var_export($expected, true) . "\n";
        echo '  actual:   ' . var_export($actual, true) . "\n";
    }
}

// ---------------------------------------------------------------------------
// deepMergeArrays()
// ---------------------------------------------------------------------------

check(
    ['a' => 1, 'b' => 2],
    shopPrefillPluginHelper::deepMergeArrays(['a' => 1], ['b' => 2]),
    'непересекающиеся ключи складываются'
);

check(
    ['a' => 2],
    shopPrefillPluginHelper::deepMergeArrays(['a' => 1], ['a' => 2]),
    'скаляр override побеждает скаляр base'
);

check(
    ['shipping' => ['id' => 5, 'rate_id' => 10]],
    shopPrefillPluginHelper::deepMergeArrays(
        ['shipping' => ['id' => 1, 'rate_id' => 10]],
        ['shipping' => ['id' => 5]]
    ),
    'вложенные массивы сливаются рекурсивно, а не заменяются целиком'
);

check(
    ['a' => ['x' => 1]],
    shopPrefillPluginHelper::deepMergeArrays(['a' => 1], ['a' => ['x' => 1]]),
    'override-массив поверх base-скаляра — override побеждает целиком'
);

check(
    ['a' => 5],
    shopPrefillPluginHelper::deepMergeArrays(['a' => ['x' => 1]], ['a' => 5]),
    'override-скаляр поверх base-массива — override побеждает целиком (не сливается по ключам)'
);

// Регресс issue-40: результат не должен быть тем же массивом, что подмешавшийся base
// на всех уровнях вложенности — иначе запись в $result через ссылку задела бы исходный $base.
$base   = ['shipping' => ['custom' => ['floor' => '0']]];
$before = $base;

$result                                   = shopPrefillPluginHelper::deepMergeArrays($base, ['shipping' => ['custom' => ['floor' => '5']]]);
$result['shipping']['custom']['floor']    = 'mutated';
$result['shipping']['new_key']            = 'added';

check($before, $base, 'deepMergeArrays() не мутирует переданный $base даже на вложенных уровнях');
check('0', $base['shipping']['custom']['floor'], '$base не изменился после записи во вложенный ключ результата');

// $override тоже не мутируется
$override = ['b' => ['y' => 1]];
$before_o = $override;
$res2     = shopPrefillPluginHelper::deepMergeArrays(['a' => 1], $override);
$res2['b']['y'] = 'mutated';

check($before_o, $override, 'deepMergeArrays() не мутирует переданный $override');

check([], shopPrefillPluginHelper::deepMergeArrays([], []), 'два пустых массива дают пустой массив');

// ---------------------------------------------------------------------------
// stripEmptyLeaves()
// ---------------------------------------------------------------------------

check(
    ['a' => 1],
    shopPrefillPluginHelper::stripEmptyLeaves(['a' => 1, 'b' => null, 'c' => '']),
    'null и пустая строка убираются, остальное остаётся'
);

check(
    ['floor' => '0', 'flag' => false, 'zero' => 0],
    shopPrefillPluginHelper::stripEmptyLeaves(['floor' => '0', 'flag' => false, 'zero' => 0]),
    "'0', false и 0 — валидные значения, не трогаем (этаж '0' в кастомном поле адреса)"
);

check(
    [],
    shopPrefillPluginHelper::stripEmptyLeaves(['a' => null, 'b' => '']),
    'все листья пустые — результат пустой массив'
);

check(
    ['keep' => ['x' => 1]],
    shopPrefillPluginHelper::stripEmptyLeaves(['keep' => ['x' => 1, 'y' => null], 'drop' => ['z' => '']]),
    'вложенный массив, опустевший после чистки, целиком убирается из родителя'
);

check(
    ['a' => ['b' => ['c' => 1]]],
    shopPrefillPluginHelper::stripEmptyLeaves(['a' => ['b' => ['c' => 1, 'd' => null]]]),
    'рекурсия чистит листья на любой глубине'
);

check([], shopPrefillPluginHelper::stripEmptyLeaves([]), 'пустой массив на входе — пустой на выходе');

check(
    ['a' => ['x' => '0']],
    shopPrefillPluginHelper::stripEmptyLeaves(['a' => ['x' => '0', 'y' => '']]),
    "вложенный '0' сохраняется, сосед-пустышка в том же массиве убирается"
);

// ---------------------------------------------------------------------------
echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
