<?php

require_once dirname(__DIR__) . '/lib/classes/fillparams/shopPrefillPluginFillParams.class.php';
require_once dirname(__DIR__) . '/lib/classes/fillparams/shopPrefillPluginFillParamsCollection.class.php';

/**
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

$default = shopPrefillPluginFillParamsCollection::DEFAULT_LIMIT;
$max     = shopPrefillPluginFillParamsCollection::MAX_LIMIT;

// Настройка приходит из БД строкой, а мимо формы — чем угодно
$cases = [
    // [сырое значение настройки, ожидаемый лимит, что защищаем]
    [null, $default, 'настройка не задана — работает умолчание'],
    ['', $default, 'пустая строка (filter_var отдаёт false) — умолчание'],
    ['abc', $default, 'мусор вместо числа — умолчание'],
    [0, $default, 'ноль — это «не задано», а не «ноль карточек»: за отключение отвечает флаг'],
    ['0', $default, 'ноль строкой — так же'],
    [-3, $default, 'отрицательное — умолчание'],
    [1, 1, 'минимум допустим'],
    ['7', 7, 'значение из БД приходит строкой'],
    [$max, $max, 'потолок допустим'],
    [$max + 1, $max, 'выше потолка — клампим, а не доверяем форме'],
    [999, $max, 'запрос мимо формы не расширяет диалог'],
];

foreach ($cases as [$raw, $expected, $message]) {
    assertSameValue($expected, shopPrefillPluginFillParamsCollection::normalizeLimit($raw), $message);
}

// Лимит коллекции режет выдачу независимо от того, сколько вариантов в неё положили
$collection = new shopPrefillPluginFillParamsCollection();
for ($i = 1; $i <= 3; $i++) {
    $params = new shopPrefillPluginFillParams();
    $params->setId($i);
    $collection->add($params);
}

assertSameValue(3, count($collection->toArray()), 'без лимита отдаются все элементы');
assertSameValue(2, count($collection->toArray(2)), 'лимит режет выдачу');

echo "FillParamsCollectionLimitTest: OK\n";
