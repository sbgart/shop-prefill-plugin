<?php

/**
 * Проверяет фикс issue-80#2: shopCheckoutShippingStep::process() при Shop-Script
 * fast_render выходит до вычисления data.shipping.selected_variant и кладёт в errors
 * служебный сентинел ['fast_render' => true]. getShippingType() в этот момент пуст
 * точно так же, как при реальной недоступности варианта — их обязан различать
 * isFastRender(), иначе renderDeliveryUnavailableScript() покажет покупателю
 * ложное «Выбранный способ доставки недоступен» сразу после apply-delivery + reload,
 * до того как фоновый calculate успеет по-настоящему посчитать доставку.
 *
 * Запуск: php tests/CheckoutStateFastRenderTest.php
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

// --- fast_render: шаг shipping не считался в этом ответе ---
$params = [
    'data'          => ['shipping' => []],
    'errors'        => [['fast_render' => true]],
    'error_step_id' => 'shipping',
];
$state = new shopPrefillCheckoutState($params);
check(true, $state->isFastRender(), 'fast_render: isFastRender() true');
check('', $state->getShippingType(), 'fast_render: getShippingType() пуст (variant ещё не посчитан)');
check([], $state->getRegularErrors(), 'fast_render: сентинел не считается регулярной ошибкой');

// --- реальная недоступность: shipping посчитан, вариант не выбран ---
$params = [
    'data'          => ['shipping' => []],
    'errors'        => [['name' => 'shipping[variant_id]', 'text' => 'Please select shipping option.', 'section' => 'shipping']],
    'error_step_id' => 'shipping',
];
$state = new shopPrefillCheckoutState($params);
check(false, $state->isFastRender(), 'реальная недоступность: isFastRender() false');
check('', $state->getShippingType(), 'реальная недоступность: getShippingType() тоже пуст');
check(1, count($state->getRegularErrors()), 'реальная недоступность: ошибка проходит как регулярная');

// --- успех: variant посчитан ---
$params = [
    'data' => ['shipping' => ['selected_variant' => ['type' => 'todoor', 'variant_id' => '5.1']]],
];
$state = new shopPrefillCheckoutState($params);
check(false, $state->isFastRender(), 'успех: isFastRender() false (errors вообще нет)');
check('todoor', $state->getShippingType(), 'успех: getShippingType() возвращает тип варианта');

echo "\n{$checks} проверок, {$failures} провалено\n";
exit($failures > 0 ? 1 : 0);
