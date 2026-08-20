<?php

/**
 * Проверяет разделение двух признаков непустоты секции (issue-59):
 *   - isSectionOwnedByCustomer() — «покупатель держал секцию в руках, писать нельзя» (учитывает `html`)
 *   - isSectionFilled()          — «в секции есть реальные данные» (`html` игнорируется)
 *
 * Главный щит от регресса: секция со стёртым вручную полем не должна предзаполняться заново,
 * иначе город/улицу/комментарий станет невозможно очистить.
 *
 * Запуск: php tests/SectionCheckerOwnershipVsDataTest.php
 */

// Логгер плагина требует поднятого Webasyst — на уровне этого класса он только пишет debug.
if (!class_exists('shopPrefillPluginLog')) {
    class shopPrefillPluginLog
    {
        public static function debug($message, array $context = []): void {}
        public static function info($message, array $context = []): void {}
        public static function warning($message, array $context = []): void {}
        public static function error($message, array $context = []): void {}
    }
}

require_once dirname(__DIR__) . '/lib/classes/sections/shopPrefillPluginSectionChecker.class.php';

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
        echo "  FAIL  {$message}: expected " . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL;
    }
}

function params(string $section, $data): array
{
    return ['order' => [$section => $data]];
}

$all_enabled = ['customer' => true, 'delivery' => true, 'payment' => true, 'confirm' => true];
$checker     = new shopPrefillPluginSectionChecker($all_enabled);

// Секции со свободным вводом: `html` делает секцию собственностью покупателя.
$free_text = [
    'auth'    => ['path' => ['data', 'email'], 'value' => 'a@b.co'],
    'region'  => ['path' => ['city'], 'value' => 'Москва'],
    'details' => ['path' => ['shipping_address', 'street'], 'value' => 'ул. Гостевая'],
    'confirm' => ['path' => ['comment'], 'value' => 'позвонить'],
];

// Секции с выбором из вариантов: стереть значение нельзя, `html` там не нужен.
$choice = [
    'shipping' => ['path' => ['type_id'], 'value' => 'courier'],
    'payment'  => ['path' => ['id'], 'value' => '7'],
];

function nest(array $path, $value): array
{
    $result = $value;
    foreach (array_reverse($path) as $key) {
        $result = [$key => $result];
    }
    return $result;
}

echo "1. Пустая секция — ничья, предзаполнять можно" . PHP_EOL;
foreach (array_merge(array_keys($free_text), array_keys($choice)) as $section) {
    check(false, $checker->isSectionOwnedByCustomer($section, params($section, [])), "{$section}: owned");
    check(false, $checker->isSectionFilled($section, params($section, [])), "{$section}: filled");
    check(true, $checker->canPrefillSection($section, params($section, [])), "{$section}: canPrefill");
}

echo "2. Только служебный html" . PHP_EOL;
foreach ($free_text as $section => $_) {
    $p = params($section, ['html' => 'only']);
    check(true, $checker->isSectionOwnedByCustomer($section, $p), "{$section}: owned (html)");
    check(false, $checker->isSectionFilled($section, $p), "{$section}: НЕ filled (html не данные)");
    check(false, $checker->canPrefillSection($section, $p), "{$section}: писать нельзя");
}
foreach ($choice as $section => $_) {
    $p = params($section, ['html' => 'only']);
    check(false, $checker->isSectionOwnedByCustomer($section, $p), "{$section}: html не влияет");
    check(false, $checker->isSectionFilled($section, $p), "{$section}: НЕ filled");
    check(true, $checker->canPrefillSection($section, $p), "{$section}: предзаполнять можно");
}

echo "3. Реальные данные без html" . PHP_EOL;
foreach (array_merge($free_text, $choice) as $section => $spec) {
    $p = params($section, nest($spec['path'], $spec['value']));
    check(true, $checker->isSectionOwnedByCustomer($section, $p), "{$section}: owned");
    check(true, $checker->isSectionFilled($section, $p), "{$section}: filled");
    check(false, $checker->canPrefillSection($section, $p), "{$section}: не перезаписываем");
}

echo "4. ЩИТ ОТ РЕГРЕССА: покупатель стёр поле (html остался, значение пустое)" . PHP_EOL;
foreach ($free_text as $section => $spec) {
    $p = params($section, nest($spec['path'], '') + ['html' => 'only']);
    check(true, $checker->isSectionOwnedByCustomer($section, $p), "{$section}: секция всё ещё его");
    check(false, $checker->isSectionFilled($section, $p), "{$section}: данных нет");
    check(false, $checker->canPrefillSection($section, $p), "{$section}: НЕ возвращаем стёртое значение");
}

echo "5. Снапшот: пустая секция с html не восстанавливается" . PHP_EOL;
foreach ($free_text as $section => $_) {
    check(false, $checker->isSectionFilled($section, params($section, ['html' => 1])),
        "{$section}: снапшот с одним html — нечего восстанавливать");
}
foreach ($free_text as $section => $spec) {
    check(true, $checker->isSectionFilled($section, params($section, nest($spec['path'], $spec['value']) + ['html' => 1])),
        "{$section}: снапшот с данными — восстанавливаем");
}

echo "6. Каждое ключевое поле auth работает поодиночке" . PHP_EOL;
foreach (['data.email' => 'a@b.co', 'data.phone' => '79001234567', 'data.firstname' => 'Иван'] as $path => $value) {
    $p = params('auth', nest(explode('.', $path), $value));
    check(true, $checker->isSectionFilled('auth', $p), "auth: только {$path}");
    check(true, $checker->isSectionOwnedByCustomer('auth', $p), "auth: только {$path} (owned)");
}
// Сценарий из TESTS.md: стёрли email, телефон остался — секция всё ещё с данными
$p = params('auth', ['html' => 1, 'data' => ['email' => '', 'phone' => '79001234567']]);
check(true, $checker->isSectionFilled('auth', $p), 'auth: email стёрт, телефон остался — данные есть');
check(false, $checker->canPrefillSection('auth', $p), 'auth: email стёрт — не дозаполняем секцию');

echo "7. Структурный инвариант двух списков" . PHP_EOL;
$reflection = new ReflectionClass('shopPrefillPluginSectionChecker');
$ownership  = $reflection->getConstant('SECTION_OWNERSHIP_FIELDS');
$data       = $reflection->getConstant('SECTION_DATA_FIELDS');

check(array_keys($ownership), array_keys($data), 'списки описывают одни и те же секции');
foreach ($ownership as $section => $fields) {
    check([], array_values(array_diff($data[$section], $fields)),
        "{$section}: DATA — подмножество OWNERSHIP");
    check(array_values(array_diff($fields, $data[$section])), array_values(array_intersect($fields, ['html'])),
        "{$section}: списки различаются ровно на html");
}

echo "8. Список владения закреплён дословно" . PHP_EOL;
// Пока список не расширен, «занятыми» секции свободного ввода держит один лишь `html`,
// и предзаполнение вправе войти в секцию, где покупатель уже заполнил другое поле группы
// (страна без города, подъезд без улицы). Расширение списка до всей секции — и есть фикс
// issue-65; вместе с ним переписываются блок 7 (инвариант «различаются ровно на html»)
// и этот блок.
check([
    'auth'     => ['data.email', 'data.phone', 'data.firstname', 'html'],
    'region'   => ['city', 'html'],
    'shipping' => ['type_id'],
    'details'  => ['shipping_address.street', 'html'],
    'payment'  => ['id'],
    'confirm'  => ['comment', 'html'],
], $ownership, 'SECTION_OWNERSHIP_FIELDS дословно совпадает с прежним SECTION_KEY_FIELDS');

echo "9. Данные всегда означают владение (но не наоборот)" . PHP_EOL;
foreach (array_merge($free_text, $choice) as $section => $spec) {
    $p = params($section, nest($spec['path'], $spec['value']));
    if ($checker->isSectionFilled($section, $p)) {
        check(true, $checker->isSectionOwnedByCustomer($section, $p), "{$section}: filled ⟹ owned");
    }
}

echo "10. Минимум группы для дзен-режима" . PHP_EOL;
// delivery: только shipping.type_id. details и region в минимум не входят —
// их данные не переживают короткое замыкание конвейера шагов.
check(false, $checker->isGroupMinimumFilled('delivery', params('shipping', [])), 'delivery: пусто');
check(false, $checker->isGroupMinimumFilled('delivery', params('shipping', ['html' => 'only'])), 'delivery: один html — не минимум');
check(true,  $checker->isGroupMinimumFilled('delivery', params('shipping', ['type_id' => 'courier'])), 'delivery: есть type_id');

// Ключевой сценарий: короткое замыкание съело улицу, но type_id уцелел → группа заполнена
$short_circuited = ['order' => [
    'shipping' => ['type_id' => 'todoor', 'variant_id' => '33.courier', 'html' => 'only'],
    'details'  => ['html' => 'only'],
]];
check(true, $checker->isGroupMinimumFilled('delivery', $short_circuited),
    'delivery: короткое замыкание съело улицу — минимум всё равно выполнен');
check(false, $checker->isSectionFilled('details', $short_circuited),
    'details при этом действительно пуст (потому и не в минимуме)');

// Самовывоз без адресных полей — тот же расклад, отдельной логики не нужно
check(true, $checker->isGroupMinimumFilled('delivery', ['order' => [
    'shipping' => ['type_id' => 'pickup', 'variant_id' => '12.pickup.DES7'],
    'details'  => ['html' => 'only'],
]]), 'delivery: самовывоз без адресных полей');

check(false, $checker->isGroupMinimumFilled('payment', params('payment', ['html' => '1'])), 'payment: пусто');
check(true,  $checker->isGroupMinimumFilled('payment', params('payment', ['id' => '16'])), 'payment: есть id');

check(false, $checker->isGroupMinimumFilled('customer', params('auth', ['html' => 1])), 'customer: пусто');
check(true,  $checker->isGroupMinimumFilled('customer', params('auth', ['data' => ['phone' => '79001234567']])), 'customer: один телефон');
check(true,  $checker->isGroupMinimumFilled('customer', params('auth', ['html' => 1, 'data' => ['email' => '', 'phone' => '79001234567']])),
    'customer: email стёрт, телефон остался');
check(false, $checker->isGroupMinimumFilled('customer', params('auth', ['html' => 1, 'data' => ['email' => '', 'phone' => '']])),
    'customer: стёрли всё — разворачиваем');

check(true, $checker->isGroupMinimumFilled('confirm', params('confirm', [])), 'confirm: не сворачивается, минимума нет');
check(true, $checker->isGroupMinimumFilled('unknown', []), 'неизвестная группа не мешает');

echo "11. Выключенная группа перевешивает всё" . PHP_EOL;
$off = new shopPrefillPluginSectionChecker(
    ['customer' => false, 'delivery' => false, 'payment' => false, 'confirm' => false]
);
foreach (array_merge(array_keys($free_text), array_keys($choice)) as $section) {
    check(false, $off->canPrefillSection($section, params($section, [])), "{$section}: группа выключена");
}

echo "12. Пустые значения не считаются данными (существующее поведение isValueFilled)" . PHP_EOL;
foreach (['', null, '0', 0] as $empty) {
    $p = params('region', ['city' => $empty]);
    check(false, $checker->isSectionFilled('region', $p), 'region: city=' . var_export($empty, true));
}

echo "13. Устойчивость к мусору" . PHP_EOL;
check(false, $checker->isSectionFilled('region', []), 'нет ключа order');
check(false, $checker->isSectionOwnedByCustomer('region', []), 'нет ключа order (owned)');
check(false, $checker->isSectionFilled('unknown', params('unknown', ['x' => 1])), 'неизвестная секция');
check(false, $checker->isSectionOwnedByCustomer('unknown', params('unknown', ['x' => 1])), 'неизвестная секция (owned)');
check(false, $checker->isSectionFilled('region', params('region', 'строка вместо массива')), 'секция не массив');
check(false, $checker->isSectionFilled('details', params('details', ['shipping_address' => 'не массив'])), 'вложенный путь упирается в скаляр');

echo PHP_EOL;
if ($failures === 0) {
    echo "OK: {$checks} проверок пройдено" . PHP_EOL;
    exit(0);
}
echo "ПРОВАЛЕНО: {$failures} из {$checks}" . PHP_EOL;
exit(1);
