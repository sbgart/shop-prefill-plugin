<?php

/**
 * Проверяет разделение двух признаков непустоты секции:
 *   - isSectionOwnedByCustomer() — «покупатель держал секцию в руках, писать нельзя» — только `html`
 *   - isSectionFilled()          — «в секции есть реальные данные» (`html` не считается)
 *
 * С 22.08.2026 владение — единственный сигнал `html`, одинаковый для всех шести секций
 * (issue-65): раньше часть секций считалась занятой по содержимому (`city`, `type_id`...),
 * что путало происхождение значения с его содержимым — системные дефолты (страна, единственный
 * способ оплаты) и чужие плагины (cityselect), пишущие в сессию до первого рендера чекаута,
 * становились ложным владением. Теперь «данные есть» и «владение» — независимые вопросы:
 * данные без html не блокируют перезапись (блок 3), а голый html без данных блокирует (блок 2) —
 * это и есть щит от issue-59: без него стёртое значение вернётся.
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

// Путь и тестовое значение ключевого DATA-поля каждой секции. Владение (html) теперь
// одинаково для всех шести — разделения на «свободный ввод» и «выбор» для него больше нет.
$sections = [
    'auth'     => ['path' => ['data', 'email'], 'value' => 'a@b.co'],
    'region'   => ['path' => ['city'], 'value' => 'Москва'],
    'details'  => ['path' => ['shipping_address', 'street'], 'value' => 'ул. Гостевая'],
    'confirm'  => ['path' => ['comment'], 'value' => 'позвонить'],
    'shipping' => ['path' => ['variant_id'], 'value' => '33.courier'],
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
foreach (array_keys($sections) as $section) {
    check(false, $checker->isSectionOwnedByCustomer($section, params($section, [])), "{$section}: owned");
    check(false, $checker->isSectionFilled($section, params($section, [])), "{$section}: filled");
    check(true, $checker->canPrefillSection($section, params($section, [])), "{$section}: canPrefill");
}

echo "2. Только служебный html — владение одинаково для любой секции" . PHP_EOL;
foreach ($sections as $section => $_) {
    $p = params($section, ['html' => 'only']);
    check(true, $checker->isSectionOwnedByCustomer($section, $p), "{$section}: owned (html)");
    check(false, $checker->isSectionFilled($section, $p), "{$section}: НЕ filled (html не данные)");
    check(false, $checker->canPrefillSection($section, $p), "{$section}: писать нельзя");
}

echo "3. Реальные данные без html — НЕ владение (ключевое поведение issue-65)" . PHP_EOL;
foreach ($sections as $section => $spec) {
    $p = params($section, nest($spec['path'], $spec['value']));
    check(true, $checker->isSectionFilled($section, $p), "{$section}: filled");
    check(false, $checker->isSectionOwnedByCustomer($section, $p), "{$section}: без html НЕ owned, даже если данные есть");
    check(true, $checker->canPrefillSection($section, $p), "{$section}: можно перезаписать — ровно то, что нужно против системных дефолтов и cityselect");
}

echo "4. ЩИТ ОТ РЕГРЕССА: покупатель стёр поле (html остался, значение пустое)" . PHP_EOL;
foreach ($sections as $section => $spec) {
    $p = params($section, nest($spec['path'], '') + ['html' => 'only']);
    check(true, $checker->isSectionOwnedByCustomer($section, $p), "{$section}: секция всё ещё его");
    check(false, $checker->isSectionFilled($section, $p), "{$section}: данных нет");
    check(false, $checker->canPrefillSection($section, $p), "{$section}: НЕ возвращаем стёртое значение");
}

echo "5. isSectionFilled: одного html недостаточно, нужны реальные данные" . PHP_EOL;
foreach ($sections as $section => $_) {
    check(false, $checker->isSectionFilled($section, params($section, ['html' => 1])),
        "{$section}: один html — данных нет");
}
foreach ($sections as $section => $spec) {
    check(true, $checker->isSectionFilled($section, params($section, nest($spec['path'], $spec['value']) + ['html' => 1])),
        "{$section}: данные плюс html — filled");
}

echo "6. Каждое ключевое поле auth работает поодиночке (isSectionFilled), но без html не owned" . PHP_EOL;
foreach (['data.email' => 'a@b.co', 'data.phone' => '79001234567', 'data.firstname' => 'Иван'] as $path => $value) {
    $p = params('auth', nest(explode('.', $path), $value));
    check(true, $checker->isSectionFilled('auth', $p), "auth: только {$path}");
    check(false, $checker->isSectionOwnedByCustomer('auth', $p), "auth: только {$path}, без html — НЕ owned");
}
// Сценарий из TESTS.md: стёрли email, телефон остался — секция всё ещё с данными
$p = params('auth', ['html' => 1, 'data' => ['email' => '', 'phone' => '79001234567']]);
check(true, $checker->isSectionFilled('auth', $p), 'auth: email стёрт, телефон остался — данные есть');
check(false, $checker->canPrefillSection('auth', $p), 'auth: email стёрт — не дозаполняем секцию');

echo "7. Структурный инвариант: владение — только html, и не пересекается с DATA" . PHP_EOL;
$reflection = new ReflectionClass('shopPrefillPluginSectionChecker');
$ownership  = $reflection->getConstant('SECTION_OWNERSHIP_FIELDS');
$data       = $reflection->getConstant('SECTION_DATA_FIELDS');

check(array_keys($ownership), array_keys($data), 'списки описывают одни и те же секции');
foreach ($ownership as $section => $fields) {
    check(['html'], $fields, "{$section}: владение — это ровно ['html']");
    check([], array_values(array_intersect($data[$section], $fields)),
        "{$section}: DATA и OWNERSHIP не пересекаются — html не входит в DATA");
}

echo "8. Список владения закреплён дословно" . PHP_EOL;
// Владение больше не зависит от секции: единственный сигнал — html, одинаковый везде.
// Если здесь снова появится содержательное поле (city, type_id...) — это регресс issue-65:
// системный дефолт страны или единственный способ оплаты опять станут ложным владением.
check([
    'auth'     => ['html'],
    'region'   => ['html'],
    'shipping' => ['html'],
    'details'  => ['html'],
    'payment'  => ['html'],
    'confirm'  => ['html'],
], $ownership, 'SECTION_OWNERSHIP_FIELDS — везде только html');

echo "9. Минимум группы для дзен-режима" . PHP_EOL;
// delivery: только shipping.variant_id. details и region в минимум не входят —
// их данные не переживают короткое замыкание конвейера шагов.
check(false, $checker->isGroupMinimumFilled('delivery', params('shipping', [])), 'delivery: пусто');
check(false, $checker->isGroupMinimumFilled('delivery', params('shipping', ['html' => 'only'])), 'delivery: один html — не минимум');
check(true,  $checker->isGroupMinimumFilled('delivery', params('shipping', ['variant_id' => '33.courier'])), 'delivery: есть variant_id');
// Тип без варианта — открытая вкладка, а не выбор (Z2): минимум не выполнен, даже если
// он один в секции. Это утверждение краснеет первым, если минимум откатить на type_id.
check(false, $checker->isGroupMinimumFilled('delivery', params('shipping', ['type_id' => 'courier'])), 'delivery: только type_id, без variant_id — НЕ минимум');
// '0' — легитимный rate_id самовывоза (см. FillParamsHelperDeliveryVariantIdTest), не путать
// с isValueFilled(), где '0' — незаполненность человеческого выбора. Здесь variant_id — строка
// '37.0', а не '0', так что isValueFilled() её не отбрасывает.
check(true, $checker->isGroupMinimumFilled('delivery', params('shipping', ['variant_id' => '37.0'])), 'delivery: variant_id самовывоза с rate_id=0');

// Ключевой сценарий: короткое замыкание съело улицу, но variant_id уцелел → группа заполнена
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
    'shipping' => ['variant_id' => '12.pickup.DES7'],
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

echo "10. Выключенная группа перевешивает всё" . PHP_EOL;
$off = new shopPrefillPluginSectionChecker(
    ['customer' => false, 'delivery' => false, 'payment' => false, 'confirm' => false]
);
foreach (array_keys($sections) as $section) {
    check(false, $off->canPrefillSection($section, params($section, [])), "{$section}: группа выключена");
}

echo "11. Пустые значения не считаются данными (существующее поведение isValueFilled)" . PHP_EOL;
foreach (['', null, '0', 0] as $empty) {
    $p = params('region', ['city' => $empty]);
    check(false, $checker->isSectionFilled('region', $p), 'region: city=' . var_export($empty, true));
}

echo "12. Устойчивость к мусору" . PHP_EOL;
check(false, $checker->isSectionFilled('region', []), 'нет ключа order');
check(false, $checker->isSectionOwnedByCustomer('region', []), 'нет ключа order (owned)');
check(false, $checker->isSectionFilled('unknown', params('unknown', ['x' => 1])), 'неизвестная секция');
check(false, $checker->isSectionOwnedByCustomer('unknown', params('unknown', ['x' => 1])), 'неизвестная секция (owned)');
check(false, $checker->isSectionFilled('region', params('region', 'строка вместо массива')), 'секция не массив');
check(false, $checker->isSectionFilled('details', params('details', ['shipping_address' => 'не массив'])), 'вложенный путь упирается в скаляр');

echo "13. isSectionMechanicallyClean: секция не прислала ни одного своего поля" . PHP_EOL;
// Механизм эхо-кэшей (payment и delivery) общий для всех шести секций.
// Критерий — присутствие собственных полей секции, а НЕ значение html: ядро кодирует
// одно и то же «просто перерисуй меня» двумя значениями — 'only' у shipping/details и
// 1 у payment (form.js:1871, :2429, :2660). Сравнение с 'only' стирало эхо оплаты ровно
// тогда, когда оно было нужно.
// См. docs/bugs/shipping-payment-identity-lost-after-snapshot-removal.md
foreach (array_keys($sections) as $section) {
    check(true, $checker->isSectionMechanicallyClean($section, params($section, ['html' => 'only'])),
        "{$section}: только html==='only' — секция промолчала");
    check(true, $checker->isSectionMechanicallyClean($section, params($section, ['html' => 1])),
        "{$section}: только html===1 — тоже промолчала (протокол payment)");
    check(true, $checker->isSectionMechanicallyClean($section, params($section, [])),
        "{$section}: секция пуста — своих полей тоже нет");
}

// Ключевое различение: отрисованная секция всегда шлёт хотя бы одно своё поле,
// скрытые инпуты сериализуются даже пустыми. Значит «покупатель не выбрал»
// от «секции не было на странице» отличимо, и P2 не нарушается.
check(false, $checker->isSectionMechanicallyClean('shipping', params('shipping', ['type_id' => '', 'html' => 'only'])),
    'shipping: пустой type_id — покупатель на секции, просто не выбрал');
check(false, $checker->isSectionMechanicallyClean('shipping', params('shipping', ['type_id' => 'pickup', 'variant_id' => '', 'html' => 'only'])),
    'shipping: тип выбран, вариант ещё нет — секция говорила сама за себя');
check(false, $checker->isSectionMechanicallyClean('payment', params('payment', ['id' => '20', 'html' => 1])),
    'payment: обычный перерендер с выбором — не молчание (иначе эхо перестало бы сохраняться)');
check(false, $checker->isSectionMechanicallyClean('details', params('details', ['shipping_address' => [], 'html' => 'only'])),
    'details: пустой подмассив адреса — всё равно своё поле');

// РЕГРЕСС-ЩИТ. Ядро рендерит shipping[service_agreement] внутри формы секции region
// (region.html:476-484), а region короткое замыкание переживает. Поэтому order.shipping
// в чекауте с включённым согласием НИКОГДА не приходит «только с html», и критерий
// «нет ключей кроме html» давал ложное «секция говорила» — эхо доставки стиралось
// на каждом запросе. Замерено в браузере 24.08.2026.
check(true, $checker->isSectionMechanicallyClean('shipping', params('shipping', ['service_agreement' => '1', 'html' => 'only'])),
    'shipping: чужое поле service_agreement из формы region не считается разговором');
check(false, $checker->isSectionMechanicallyClean('shipping', params('shipping', ['service_agreement' => '1', 'type_id' => 'todoor', 'variant_id' => '33.courier', 'html' => 'only'])),
    'shipping: своё поле рядом с чужим — секция говорила');
check(true, $checker->isSectionMechanicallyClean('region', params('region', ['possible_address' => ['city' => 'Москва']])),
    'region: possible_address приходит из формы shipping — тоже чужое поле');

check(false, $checker->isSectionMechanicallyClean('payment', []), 'нет ключа order');
check(false, $checker->isSectionMechanicallyClean('unknown', params('unknown', ['html' => 'only'])),
    'неизвестная секция: состав своих полей неизвестен, молчание не утверждаем');
check(false, $checker->isSectionMechanicallyClean('region', params('region', 'строка вместо массива')),
    'секция не массив');

echo PHP_EOL;
if ($failures === 0) {
    echo "OK: {$checks} проверок пройдено" . PHP_EOL;
    exit(0);
}
echo "ПРОВАЛЕНО: {$failures} из {$checks}" . PHP_EOL;
exit(1);
