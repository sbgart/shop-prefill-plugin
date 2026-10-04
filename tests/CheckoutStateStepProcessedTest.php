<?php

/**
 * `CheckoutState::wasStepProcessed()` — «ядро посчитало выбор шага в этом запросе».
 *
 * Зачем. Детектор потери выбора (P10) показывал «Нужно выбрать другую доставку» при любой
 * ошибке авторизации или региона: он принимал «в ответе нет варианта» за потерю, а шаг
 * доставки в этом запросе вообще не считался. Прежний признак `isStepSkipped()` (пустой
 * `vars.shipping`) не видит этого: при ошибке выше `prepare()` шага, которому запрошен HTML,
 * кладёт в `vars.shipping` отрисованный `html`, и массив не пуст
 * (docs/bugs/lost-choice-false-positive-on-upstream-error.md).
 *
 * Фикстуры — не догадки, а формы `vars.<шаг>`, снятые с живого `/order/calculate/` гостем
 * 04.10.2026 (урок F-35: тест, написанный по предположениям о форме ответа, проходит и ломается
 * в бою). Для каждой формы — два режима запроса: `html=1` и `html=only` (так шлёт браузер;
 * ядро вырезает `types` у доставки и `methods` у оплаты, а `selected_*` оставляет).
 *
 * Последний блок собирает решение так, как это делает хук подтверждения (`isFastRender() ||
 * !wasStepProcessed()` → `decide()`), и запирает карту из отчёта: ошибка auth/region — тишина,
 * настоящая потеря — диалог.
 *
 * Мутации, каждая обязана дать провал:
 *   - вернуть в хук `isStepSkipped('shipping')` вместо `!wasStepProcessed('shipping')`;
 *   - в wasStepProcessed() считать признаком `types` (пропадает при html=only);
 *   - в wasStepProcessed() считать признаком непустой массив (вернётся ложное срабатывание);
 *   - убрать `selected_variant_id` из карты признаков.
 *
 * Запуск: php tests/CheckoutStateStepProcessedTest.php
 */

require_once dirname(__DIR__) . '/lib/classes/checkout/shopPrefillPluginLostChoiceDetector.class.php';
require_once dirname(__DIR__) . '/lib/classes/checkout/shopPrefillCheckoutState.class.php';

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
    } else {
        echo "OK: {$message}\n";
    }
}

/** Конструктор состояния берёт параметры по ссылке — литерал передать нельзя. */
function state(array $params): shopPrefillCheckoutState
{
    return new shopPrefillCheckoutState($params);
}

// --- Живые формы vars.shipping / vars.payment (гость, 04.10.2026) -------------------------

$shipping = [
    // шаг посчитан, выбор сделан
    'healthy_html1'   => ['possible_addresses' => [], 'selected_possible_address' => null, 'selected_type_id' => 'todoor',
                          'selected_variant_id' => '33.courier', 'types' => ['todoor' => []], 'map' => [], 'html' => '<div/>'],
    'healthy_only'    => ['possible_addresses' => [], 'selected_possible_address' => null, 'selected_type_id' => 'todoor',
                          'selected_variant_id' => '33.courier', 'map' => [], 'html' => '<div/>'],
    // шаг посчитан, тип выбран, варианта нет (ошибка самого шага) — ключ ЕСТЬ, значение null
    'no_variant_html1' => ['possible_addresses' => [], 'selected_possible_address' => null, 'selected_type_id' => 'pickup',
                           'selected_variant_id' => null, 'types' => ['pickup' => []], 'map' => [], 'html' => '<div/>'],
    'no_variant_only'  => ['possible_addresses' => [], 'selected_possible_address' => null, 'selected_type_id' => 'pickup',
                           'selected_variant_id' => null, 'map' => [], 'html' => '<div/>'],
    // не считался: ошибка auth / region выше — prepare() отрисовал только html
    'upstream_error'  => ['html' => '<div/>'],
    // не считался: fast_render и запрос без html — prepare() ничего не положил
    'empty'           => [],
    // выключен настройками магазина
    'disabled'        => ['disabled' => true, 'html' => '<div/>'],
];

$payment = [
    'healthy_html1'  => ['selected_method_id' => '20', 'methods' => [20 => []], 'html' => '<div/>'],
    'healthy_only'   => ['selected_method_id' => '20', 'html' => '<div/>'],
    'upstream_error' => ['html' => '<div/>'],
    'empty'          => [],
    'disabled'       => ['disabled' => true, 'html' => '<div/>'],
];

// --- 1. Шаг доставки ----------------------------------------------------------------------

$expected_shipping = [
    'healthy_html1'     => true,
    'healthy_only'      => true,   // html=only: `types` вырезан, признак остался
    'no_variant_html1'  => true,   // посчитан, выбора нет — это потеря, а не «не считали»
    'no_variant_only'   => true,
    'upstream_error'    => false,  // ядро нарисовало HTML, но не считало: ровно тот случай, что давал ложный диалог
    'empty'             => false,
    'disabled'          => false,
];
foreach ($expected_shipping as $name => $expected) {
    check($expected, state(['vars' => ['shipping' => $shipping[$name]]])->wasStepProcessed('shipping'), "доставка/{$name}: wasStepProcessed = " . var_export($expected, true));
}

// Прежний признак ошибается именно на upstream_error — тест фиксирует, почему он заменён
check(false, state(['vars' => ['shipping' => $shipping['upstream_error']]])->isStepSkipped('shipping'), 'isStepSkipped() НЕ видит пропуск при отрисованном html (причина замены)');
check(true, state(['vars' => ['shipping' => $shipping['empty']]])->isStepSkipped('shipping'), 'isStepSkipped() по-прежнему видит пустой массив (нужен Дзен-карточкам)');

// --- 2. Шаг оплаты ------------------------------------------------------------------------

$expected_payment = [
    'healthy_html1'  => true,
    'healthy_only'   => true,
    'upstream_error' => false,
    'empty'          => false,
    'disabled'       => false,
];
foreach ($expected_payment as $name => $expected) {
    check($expected, state(['vars' => ['payment' => $payment[$name]]])->wasStepProcessed('payment'), "оплата/{$name}: wasStepProcessed = " . var_export($expected, true));
}

// --- 3. Неопределённость и чужие шаги: отвечаем «нет» (B2a) -------------------------------

check(false, state([])->wasStepProcessed('shipping'), 'нет vars вовсе → false');
check(false, state(['vars' => []])->wasStepProcessed('shipping'), 'нет vars.shipping → false');
check(false, state(['vars' => ['shipping' => 'x']])->wasStepProcessed('shipping'), 'vars.shipping не массив → false, без ошибки');
check(false, state(['vars' => ['shipping' => null]])->wasStepProcessed('shipping'), 'vars.shipping = null → false (ключ не «есть», пока нет массива)');
foreach (['auth', 'region', 'details', 'confirm', '', 'unknown'] as $step) {
    check(false, state(['vars' => [$step => ['selected_variant_id' => 'x', 'selected_method_id' => 'y']]])->wasStepProcessed($step), "шаг '{$step}' без признака → всегда false");
}

// --- 4. Карта из отчёта: решение так, как его принимает хук подтверждения -----------------

/**
 * Одно решение по доставке из состояния ответа ядра: тот же состав условий, что в
 * CheckoutHooks::renderLostChoiceScript().
 */
function deliveryDecision(array $vars_shipping, ?string $echo, ?string $selected_variant): array
{
    $state = state(['vars' => ['shipping' => $vars_shipping]]);

    return shopPrefillPluginLostChoiceDetector::decide([
        'echo_id'      => $echo,
        'step_skipped' => $state->isFastRender() || !$state->wasStepProcessed('shipping'),
        'selected_id'  => $selected_variant,
        'notified_id'  => null,
    ]);
}

$map = [
    // [описание, vars.shipping, эхо, выбранный вариант в ответе, ожидаемое lost, ожидаемая причина]
    ['здоровый запрос (контроль)',                    $shipping['healthy_html1'],   '33.courier', '33.courier', false, 'kept'],
    ['ошибка auth (битая почта / чужая / сервер)',    $shipping['upstream_error'],  '33.courier', null,         false, 'step_skipped'],
    ['ошибка region (пустой город)',                  $shipping['upstream_error'],  '33.courier', null,         false, 'step_skipped'],
    ['fast_render: первая отрисовка /order/',         $shipping['empty'],           '33.courier', null,         false, 'step_skipped'],
    ['шаг доставки выключен',                         $shipping['disabled'],        '33.courier', null,         false, 'step_skipped'],
    ['настоящая потеря (посчитано, варианта нет)',    $shipping['no_variant_only'], '36.parcel',  null,         true,  'missing_in_response'],
    ['настоящая потеря при html=1',                   $shipping['no_variant_html1'], '36.parcel', null,         true,  'missing_in_response'],
    ['здоровый запрос при html=only',                 $shipping['healthy_only'],    '33.courier', '33.courier', false, 'kept'],
];
foreach ($map as [$title, $vars, $echo, $selected, $lost, $reason]) {
    $r = deliveryDecision($vars, $echo, $selected);
    check([$lost, $reason], [$r['lost'], $r['reason']], "карта: {$title}");
}

// --- 5. Хук действительно пользуется новым признаком --------------------------------------

$hooks = file_get_contents(dirname(__DIR__) . '/lib/classes/hooks/shopPrefillPluginCheckoutHooks.class.php');
check(true, strpos($hooks, "!\$state->wasStepProcessed('shipping')") !== false, "хук подтверждения решает по !wasStepProcessed('shipping')");
check(false, strpos($hooks, "isStepSkipped('shipping')") !== false, "хук подтверждения не вернулся к isStepSkipped('shipping')");

echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
