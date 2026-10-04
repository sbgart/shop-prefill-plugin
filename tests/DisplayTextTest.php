<?php

require_once dirname(__DIR__) . '/lib/classes/helpers/shopPrefillPluginDisplayText.class.php';

/**
 * Склейка текстов для витрины: адрес в карточке «Мои варианты» и заголовок сводки доставки.
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

$checks = 0;
$check = function ($expected, $actual, string $message) use (&$checks): void {
    $checks++;
    assertSameValue($expected, $actual, $message);
};

// --- Адрес ---
$check(
    '125009, Российская Федерация, Москва, ул. Тверская, д. 15',
    shopPrefillPluginDisplayText::joinAddress(['125009', 'Российская Федерация', 'Москва', 'Москва', 'ул. Тверская, д. 15']),
    'полный адрес: регион и город совпадают — город один раз'
);
$check(
    'Российская Федерация, Москва',
    shopPrefillPluginDisplayText::joinAddress(['', 'Российская Федерация', 'Москва', 'Москва', '']),
    'самовывоз без индекса и улицы: ни запятой в начале, ни в конце'
);
$check(
    'Россия, Тульская область, Тула, ул. Мира',
    shopPrefillPluginDisplayText::joinAddress([null, 'Россия', 'Тульская область', 'Тула', 'ул. Мира']),
    'null вместо индекса пропускается'
);
$check(
    'Москва',
    shopPrefillPluginDisplayText::joinAddress(['', '', '', 'Москва', '']),
    'одно значение — без разделителей'
);
$check('', shopPrefillPluginDisplayText::joinAddress(['', null, '   ']), 'пустые части — пустая строка');
$check('', shopPrefillPluginDisplayText::joinAddress([]), 'пустой массив — пустая строка');
$check(
    'Москва, ул. Тверская, Москва',
    shopPrefillPluginDisplayText::joinAddress(['Москва', 'ул. Тверская', 'Москва']),
    'схлопываются только подряд идущие повторы: далёкий повтор — это уже другое значение'
);
$check(
    'Москва, ул. Тверская',
    shopPrefillPluginDisplayText::joinAddress(['Москва', ' москва ', 'ул. Тверская']),
    'повтор определяется без учёта регистра и пробелов'
);
$check(
    'Тверская 15, 3',
    shopPrefillPluginDisplayText::joinAddress(['Тверская 15', 3, ['вложенный массив']]),
    'число печатается, вложенный массив игнорируется'
);
$check(
    '0, Москва',
    shopPrefillPluginDisplayText::joinAddress(['0', 'Москва']),
    '«0» — не пустое значение'
);

// --- Заголовок доставки ---
$check(
    'Курьер',
    shopPrefillPluginDisplayText::deliveryHeader('Курьер по городу', 'Курьер по городу', 'Курьер'),
    'служба = тариф → заголовок «тип доставки»'
);
$check(
    'Самовывоз',
    shopPrefillPluginDisplayText::deliveryHeader(' самовывоз  из магазина', 'Самовывоз из магазина', 'Самовывоз'),
    'совпадение без учёта регистра и лишних пробелов'
);
$check(
    'СДЭК',
    shopPrefillPluginDisplayText::deliveryHeader('СДЭК', 'Курьер до двери', 'Курьер'),
    'служба ≠ тариф → заголовок не трогаем'
);
$check(
    '',
    shopPrefillPluginDisplayText::deliveryHeader('Курьер', 'Курьер', 'Курьер'),
    'служба, тариф и тип одинаковы → заголовка нет, название выводится один раз'
);
$check(
    '',
    shopPrefillPluginDisplayText::deliveryHeader('курьер', 'курьер', 'Курьер'),
    'тип совпал со службой с точностью до регистра → заголовка нет'
);
$check(
    '',
    shopPrefillPluginDisplayText::deliveryHeader('Курьер по городу', 'Курьер по городу', ''),
    'тип неизвестен, а служба и тариф одинаковы → заголовка нет'
);
$check('', shopPrefillPluginDisplayText::deliveryHeader('', '', 'Курьер'), 'нет ни службы, ни тарифа → не придумываем заголовок');
$check('', shopPrefillPluginDisplayText::deliveryHeader('', 'Курьер', 'Курьер'), 'службы нет → пусто как и было');

echo "DisplayTextTest: {$checks} проверок, 0 провалов\n";
