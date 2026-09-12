<?php

/**
 * A5 (TEST-PLAN.md §5): структура lib/config/storefront.settings.php.
 *
 *   - у каждого листа есть 'value' (лист = массив с ключом 'value' — то же правило,
 *     по которому shopPrefillPluginSettingsConfig::isField() отличает лист от группы);
 *   - opt-in по умолчанию выключен: guest.enabled, remember_me.on_order и все три
 *     integration.* — их включение меняет видимое поведение сайта или переживает данные
 *     между визитами, поэтому дефолт не может быть true;
 *   - my_delivery_variants_limit — в пределах 1..10 (диапазон, который реально клампит
 *     shopPrefillPluginFillParamsCollection::normalizeLimit()).
 *
 * Плюс: filter соответствует типу value (bool → FILTER_VALIDATE_BOOLEAN, int →
 * FILTER_VALIDATE_INT) — иначе значение по умолчанию не переживёт собственную валидацию
 * при первом сохранении формы.
 *
 * Чистый массив, PHP/Webasyst не требуются.
 *
 * Запуск: php tests/SettingsSchemaTest.php
 */

$config = require dirname(__DIR__) . '/lib/config/storefront.settings.php';

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

function isLeaf($node): bool
{
    return is_array($node) && array_key_exists('value', $node);
}

/**
 * Собирает путь => узел-лист для всего дерева. Падает (через global $malformed), если
 * встречает не-массив там, где ожидалась ветка (защита от опечатки в конфиге).
 *
 * @param array $tree
 * @return array<string, array>
 */
function collectLeaves(array $tree, string $prefix, array &$malformed): array
{
    $leaves = [];
    foreach ($tree as $key => $node) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (isLeaf($node)) {
            $leaves[$path] = $node;
            continue;
        }

        if (!is_array($node)) {
            $malformed[] = $path;
            continue;
        }

        $leaves += collectLeaves($node, $path, $malformed);
    }

    return $leaves;
}

$malformed = [];
$leaves    = collectLeaves($config, '', $malformed);

// ---------------------------------------------------------------------------
// 1. Каждый узел дерева — либо лист с 'value', либо массив-ветка; листьев много
// ---------------------------------------------------------------------------

check([], $malformed, 'в дереве настроек нет узлов, которые не являются ни массивом-веткой, ни листом с value');
check(true, count($leaves) > 10, 'в схеме достаточно листьев, чтобы обход не молчал по ошибке (пустое дерево прошло бы предыдущую проверку)');

foreach ($leaves as $path => $node) {
    check(true, array_key_exists('value', $node), "лист «{$path}» имеет ключ 'value'");
}

// ---------------------------------------------------------------------------
// 2. Opt-in по умолчанию выключен
// ---------------------------------------------------------------------------

$opt_in_off = [
    'prefill.guest.enabled',
    'prefill.remember_me.on_order',
    'prefill.integration.cityselect',
    'prefill.integration.regions',
    'prefill.integration.dp',
];

foreach ($opt_in_off as $path) {
    check(true, array_key_exists($path, $leaves), "путь «{$path}» существует в схеме");
    if (array_key_exists($path, $leaves)) {
        check(false, $leaves[$path]['value'], "opt-in «{$path}» по умолчанию выключен");
    }
}

// ---------------------------------------------------------------------------
// 3. my_delivery_variants_limit — в пределах 1..10
// ---------------------------------------------------------------------------

check(true, array_key_exists('prefill.my_delivery_variants_limit', $leaves), 'my_delivery_variants_limit есть в схеме');
$limit = $leaves['prefill.my_delivery_variants_limit']['value'] ?? null;
check(true, is_int($limit) && $limit >= 1 && $limit <= 10, "my_delivery_variants_limit={$limit} лежит в диапазоне 1..10");

// ---------------------------------------------------------------------------
// 4. filter соответствует типу value — иначе дефолт не переживёт свою же валидацию
// ---------------------------------------------------------------------------

foreach ($leaves as $path => $node) {
    $value  = $node['value'];
    $filter = $node['filter'] ?? null;

    if (is_bool($value)) {
        check(FILTER_VALIDATE_BOOLEAN, $filter, "лист «{$path}» с bool-значением имеет filter=FILTER_VALIDATE_BOOLEAN");
    } elseif (is_int($value)) {
        check(FILTER_VALIDATE_INT, $filter, "лист «{$path}» с int-значением имеет filter=FILTER_VALIDATE_INT");
    }
}

// ---------------------------------------------------------------------------
echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
