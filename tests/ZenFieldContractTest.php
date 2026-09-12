<?php

/**
 * A4 (TEST-PLAN.md §5), правило Z7: структурный замок между
 * shopPrefillPluginZenData::getAvailableFields()/getHtmlFields() и белым списком
 * HTML-полей, который ZenSummaryEscapeTest.php повторяет вручную (см. там же, вверху файла:
 * "Белый список повторяет флаги is_html из ...getAvailableFields()").
 *
 * До этого теста расхождение между ними было бы немым: getHtmlFields() всегда выводится
 * из getAvailableFields(), поэтому сам по себе он не может разойтись с полями — а вот
 * ручной список в ZenSummaryEscapeTest.php мог отстать после правки is_html и никто бы
 * не заметил, что экранирование в реальном коде вдруг закрыло (или открыло) поле, которое
 * тест всё ещё проверяет по старому списку. Здесь список зафиксирован снимком (3) —
 * изменение набора is_html красит именно этот тест, с явным указанием, какой файл обновить.
 *
 * getAvailableFields()/getHtmlFields() — чистые static-методы: единственная внешняя
 * зависимость — _wp() для текстов полей, содержимое переводов тесту не важно.
 *
 * Запуск: php tests/ZenFieldContractTest.php
 */

function _wp(string $string): string
{
    return $string;
}

require_once dirname(__DIR__) . '/lib/classes/zenmode/shopPrefillPluginZenData.class.php';

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

$fields      = shopPrefillPluginZenData::getAvailableFields();
$html_fields = shopPrefillPluginZenData::getHtmlFields();

check(true, count($fields) > 0, 'getAvailableFields() не пуст');
check(true, count($html_fields) > 0, 'getHtmlFields() не пуст');

// ---------------------------------------------------------------------------
// 1. Каждое поле с is_html === true входит в getHtmlFields()
// ---------------------------------------------------------------------------

foreach ($fields as $key => $meta) {
    if (!empty($meta['is_html'])) {
        check(true, in_array($key, $html_fields, true), "поле «{$key}» помечено is_html и входит в getHtmlFields()");
    }
}

// ---------------------------------------------------------------------------
// 2. Обратное направление: всё, что вернул getHtmlFields(), реально помечено is_html
// ---------------------------------------------------------------------------

foreach ($html_fields as $key) {
    check(
        true,
        array_key_exists($key, $fields) && !empty($fields[$key]['is_html']),
        "поле «{$key}» из getHtmlFields() существует в getAvailableFields() и помечено is_html"
    );
}

// ---------------------------------------------------------------------------
// 3. Снимок контракта — тот же список, что вручную продублирован
//    в tests/ZenSummaryEscapeTest.php. Разошлось здесь — обнови оба файла.
// ---------------------------------------------------------------------------

$expected_html_fields = [
    'shipping_rate',
    'delivery_schedule',
    'delivery_photos_html',
    'delivery_description',
    'payment_description',
    'service_agreement_hint',
];

sort($expected_html_fields);
$actual_sorted = $html_fields;
sort($actual_sorted);

check(
    $expected_html_fields,
    $actual_sorted,
    'набор HTML-полей совпадает со списком, продублированным вручную в ZenSummaryEscapeTest.php'
);

// ---------------------------------------------------------------------------
// 4. Структурная гигиена: ключи полей уникальны, каждое поле принадлежит группе
// ---------------------------------------------------------------------------

check(count($fields), count(array_unique(array_keys($fields))), 'ключи полей в getAvailableFields() уникальны');

foreach ($fields as $key => $meta) {
    check(true, !empty($meta['group']), "поле «{$key}» имеет непустой 'group'");
}

// ---------------------------------------------------------------------------
echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
