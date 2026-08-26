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

// --- isStepSkipped(): различает «шаг не считался» и «ядро осознанно ничего не предлагает» ---
// (payment-zen-card-vanishes-on-shortcircuit.md — карточка группы не должна пропадать
// вместе со скрытой секцией ядра; B2b в RULES.md)

// шаг не считался вовсе — короткое замыкание / fast_render, vars.<шаг> === []
$params = ['vars' => ['payment' => []]];
$state = new shopPrefillCheckoutState($params);
check(true, $state->isStepSkipped('payment'), 'isStepSkipped: payment=[] — шаг не считался');

// оплата осознанно выключена настройками магазина — результат непустой (ключ disabled)
$params = ['vars' => ['payment' => ['disabled' => true]]];
$state = new shopPrefillCheckoutState($params);
check(false, $state->isStepSkipped('payment'), 'isStepSkipped: payment.disabled=true — шаг отработал, оплата выключена');

// шаг отработал, но способов оплаты для этой корзины нет — тоже не пустой результат.
// Регресс-щит на ошибку первой редакции фикса: там проверялось empty(methods), и это
// давало true в обоих случаях выше — false-positive «шаг не считался» для disabled
// и для честного «способов нет» (ловушка D1: свёрнутая карточка обещала бы выбор,
// которого на чекауте нет вовсе).
$params = ['vars' => ['payment' => ['selected_method_id' => null, 'methods' => []]]];
$state = new shopPrefillCheckoutState($params);
check(false, $state->isStepSkipped('payment'), 'isStepSkipped: methods=[] после реального рендера — не пустой результат');

// happy path — способы есть
$params = ['vars' => ['payment' => ['methods' => ['16' => ['id' => '16']]]]];
$state = new shopPrefillCheckoutState($params);
check(false, $state->isStepSkipped('payment'), 'isStepSkipped: happy path — способы оплаты посчитаны');

// ключа payment в vars нет вовсе — неопределённость, а не пустота (B2a: стоковый чекаут)
$params = ['vars' => ['auth' => ['fields' => []]]];
$state = new shopPrefillCheckoutState($params);
check(false, $state->isStepSkipped('payment'), 'isStepSkipped: ключа шага в vars нет — неопределённость (B2a)');

// details: та же пара состояний, что и у payment (shipping.used = false ⇒ disabled)
$params = ['vars' => ['details' => []]];
$state = new shopPrefillCheckoutState($params);
check(true, $state->isStepSkipped('details'), 'isStepSkipped: details=[] — шаг не считался');

$params = ['vars' => ['details' => ['disabled' => true]]];
$state = new shopPrefillCheckoutState($params);
check(false, $state->isStepSkipped('details'), 'isStepSkipped: details.disabled=true — shipping.used=false, не пустота');

echo "\n{$checks} проверок, {$failures} провалено\n";
exit($failures > 0 ? 1 : 0);
