<?php

/**
 * Запирает область действия признака `data-blocked-by` (см.
 * docs/bugs/zen-blocked-dialog-on-incomplete-step.md).
 *
 * Признак получает только группа СТРОГО ПОСЛЕ упавшей: её шаги ядро не считало, и разворот
 * показал бы пустоту (D1). Сама упавшая группа и всё выше не блокируются никогда. Пока было
 * иначе, ядро кодировало «способ оплаты ещё не выбран» как ошибку шага payment, и покупатель,
 * ни разу не ошибившись, получал «Сначала нужно исправить ошибку».
 *
 * Про фикстуры. Первая редакция теста строила `vars` из головы: «при ошибке auth шаг region
 * пропущен». В реальном ответе это неверно — ядро рисует region в prepare() даже при коротком
 * замыкании (замер curl 19.09.2026: при битой почте payment получал `blocked=customer`, а
 * delivery не получал), и тест зелёно подтверждал правило, которое в жизни не работало.
 * Теперь ожидания сверены с реальной таблицей ниже, а `vars` намеренно подаётся в форме
 * реального ответа — правило от него зависеть не должно.
 *
 * Реальный ответ при битой почте (гость, curl, 19.09.2026), кнопки по секциям-носителям:
 *     auth    → customer:blocked=-        details → delivery:blocked=customer
 *     payment → payment:blocked=customer
 *
 * Запуск: php tests/ZenBlockingGroupScopeTest.php
 */

require_once dirname(__DIR__) . '/lib/classes/checkout/shopPrefillCheckoutState.class.php';

$failures = 0;
$checks   = 0;

function check($expected, $actual, string $message): void
{
    global $failures, $checks;
    $checks++;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$message}\n";
        echo '  expected: ' . var_export($expected, true) . "\n";
        echo '  actual:   ' . var_export($actual, true) . "\n";
    } else {
        echo "OK: {$message}\n";
    }
}

/**
 * Собирает $params так, как их видит render-хук.
 *
 * `vars` подаётся в форме реального ответа при коротком замыкании: региональный шаг отрисован
 * (prepare() вызывается всегда), шаги ниже упавшего пусты. Предикат на него смотреть не должен —
 * тест держит это условие явно, подавая и «правдоподобные», и «неудобные» vars.
 *
 * @param string $error_step Шаг, на котором остановился конвейер
 * @param array  $errors     Содержимое $params['errors']
 * @param array  $vars       Форма $params['vars']
 */
function state(string $error_step, array $errors, array $vars = []): shopPrefillCheckoutState
{
    $params = [
        'vars'          => $vars,
        'errors'        => $errors,
        'error_step_id' => $error_step,
    ];

    return new shopPrefillCheckoutState($params);
}

$rendered = ['html' => '<div></div>'];

$variant_required = [['name' => 'shipping[variant_id]', 'text' => 'Выберите вариант доставки.', 'section' => 'shipping']];
$method_required  = [['name' => 'payment[id]', 'text' => 'Выберите способ оплаты', 'section' => 'payment']];
$bad_email        = [['name' => 'auth[data][email]', 'text' => 'Введите корректный адрес.', 'section' => 'auth']];
$city_required    = [['name' => 'region[city]', 'text' => 'Обязательное поле', 'section' => 'region']];

// --- Оплата не выбрана: упал шаг payment, ниже только confirm ---
$s = state('payment', $method_required);
check('payment', $s->getBlockingGroup(), 'оплата не выбрана: конвейер остановлен группой payment');
check(null, $s->getBlockingGroupFor('payment'), 'оплата не выбрана: своя группа НЕ блокируется');
check(null, $s->getBlockingGroupFor('delivery'), 'оплата не выбрана: доставка выше упавшего шага, не блокируется');
check(null, $s->getBlockingGroupFor('customer'), 'оплата не выбрана: покупатель выше упавшего шага, не блокируется');

// --- Вариант доставки не выбран: своя группа delivery, ниже payment ---
$s = state('shipping', $variant_required);
check('delivery', $s->getBlockingGroup(), 'вариант не выбран: конвейер остановлен группой delivery');
check(null, $s->getBlockingGroupFor('delivery'), 'вариант не выбран: своя группа НЕ блокируется, хотя details после shipping пуст');
check('delivery', $s->getBlockingGroupFor('payment'), 'вариант не выбран: оплата ниже, блокируется');
check(null, $s->getBlockingGroupFor('customer'), 'вариант не выбран: покупатель выше, не блокируется');

// --- Ошибка региона и details: те же границы, что у shipping (одна группа) ---
$s = state('region', $city_required);
check(null, $s->getBlockingGroupFor('delivery'), 'город не указан: своя группа delivery НЕ блокируется');
check('delivery', $s->getBlockingGroupFor('payment'), 'город не указан: оплата блокируется');
$s = state('details', [['name' => 'details[shipping_address][street]', 'text' => 'Обязательное поле', 'section' => 'details']]);
check(null, $s->getBlockingGroupFor('delivery'), 'адрес не указан (details): своя группа delivery НЕ блокируется');
check('delivery', $s->getBlockingGroupFor('payment'), 'адрес не указан (details): оплата блокируется');

// --- Жёсткая ошибка auth: исходный сценарий D1, ради которого признак и вводился ---
// Реальная форма vars: region отрисован в prepare(), остальное пусто.
$short_circuit_vars = [
    'auth'     => $rendered,
    'region'   => $rendered,
    'shipping' => [],
    'details'  => [],
    'payment'  => [],
];
$s = state('auth', $bad_email, $short_circuit_vars);
check(null, $s->getBlockingGroupFor('customer'), 'битая почта: своя группа customer НЕ блокируется');
check('customer', $s->getBlockingGroupFor('delivery'), 'битая почта: доставка блокируется, хотя region в ответе есть (D1; регресс 19.09.2026)');
check('customer', $s->getBlockingGroupFor('payment'), 'битая почта: оплата блокируется (D1)');

// Предикат не читает vars: тот же результат при любом их содержимом, в том числе пустом
foreach ([[], $short_circuit_vars, ['region' => [], 'shipping' => [], 'details' => [], 'payment' => []]] as $i => $vars) {
    $s = state('auth', $bad_email, $vars);
    check('customer', $s->getBlockingGroupFor('delivery'), "битая почта: результат не зависит от vars (вариант #{$i})");
}

// --- fast_render: результат шага пуст, но это не ошибка валидации ---
$s = state('shipping', [['fast_render' => true]], ['shipping' => [], 'details' => [], 'payment' => []]);
check(null, $s->getBlockingGroupFor('payment'), 'fast_render: не блокирует ничего, хотя секции пусты');
check(null, $s->getBlockingGroupFor('delivery'), 'fast_render: доставка тоже свободна');

// --- Ошибок нет вовсе ---
$s = state('', [], ['payment' => []]);
check(null, $s->getBlockingGroupFor('payment'), 'ошибок нет: пустой результат шага сам по себе не блокирует');

// --- Неизвестные шаги и группы ---
$s = state('auth', $bad_email, $short_circuit_vars);
check(null, $s->getBlockingGroupFor('confirm'), 'confirm группой дзен-режима не является — не блокируется');
check(null, $s->getBlockingGroupFor(''), 'пустое имя группы не роняет предикат');
$s = state('confirm', [['name' => 'confirm[agreement]', 'text' => 'Нужно согласие', 'section' => 'confirm']]);
check(null, $s->getBlockingGroup(), 'ошибка confirm штатна на обычных пересчётах — конвейер ею не блокируется');
check(null, $s->getBlockingGroupFor('payment'), 'ошибка confirm: оплата не блокируется');

echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
