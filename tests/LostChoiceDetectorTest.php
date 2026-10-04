<?php

/**
 * Таблицы истинности детектора потери выбора (доставка/оплата) и правила «какой диалог».
 *
 * Мутации, каждая обязана дать провал:
 *   1. убрать ветку step_skipped — на каждой загрузке /order/ (fast_render) пойдёт «потеряно»;
 *   2. убрать ветку «нет эха» — «потеряно» у покупателя, который ничего не выбирал;
 *   3. считать потерей смену выбора на другой, а не пропажу;
 *   4. убрать ветку already_notified — предупреждение повторится на каждой перезагрузке;
 *   5. в pickDialog поставить оплату раньше доставки — при потере обеих покажется не тот диалог.
 *      Два диалога сразу вернуть нельзя по сигнатуре: pickDialog отдаёт один вид или null.
 *
 * См. docs/todo/zen-lost-variant-silent-expand.md
 *
 * Запуск: php tests/LostChoiceDetectorTest.php
 */

require_once dirname(__DIR__) . '/lib/classes/checkout/shopPrefillPluginLostChoiceDetector.class.php';

$failures = 0;
$checks   = 0;

function check(bool $ok, string $message): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "OK: {$message}\n";
        return;
    }
    $failures++;
    echo "FAIL: {$message}\n";
}

// --- decide() ---------------------------------------------------------------------------
$cases = [
    // [название => [echo, step_skipped, selected, lost, reason, notified]]
    'эха нет, выбора нет'               => [null,   false, null,   false, 'nothing_chosen'],
    'эхо пустой строкой'                => ['',     false, null,   false, 'nothing_chosen'],
    'эха нет, fast_render'              => [null,   true,  null,   false, 'nothing_chosen'],
    'эхо есть, fast_render'             => ['33.c', true,  null,   false, 'step_skipped'],
    'эхо есть, шаг не считался'         => ['33.c', true,  '',     false, 'step_skipped'],
    'эхо есть, выбор пропал (null)'     => ['33.c', false, null,   true,  'missing_in_response'],
    'эхо есть, выбор пропал (пусто)'    => ['33.c', false, '',     true,  'missing_in_response'],
    'эхо есть, выбор на месте'          => ['33.c', false, '33.c', false, 'kept'],
    'эхо есть, выбран другой'           => ['33.c', false, '34.d', false, 'kept'],
    // Предупреждали о другом выборе — о новой потере предупреждаем заново
    'предупреждали о другом'            => ['33.c', false, null,   true,  'missing_in_response', '34.d'],
    // Мёртвый выбор шлётся заново на каждой загрузке: второй раз молчим
    'уже предупреждали об этом'         => ['33.c', false, null,   false, 'already_notified', '33.c'],
    'предупреждали, но шаг не считался' => ['33.c', true,  null,   false, 'step_skipped', '33.c'],
    // Оплата: id из эха и пустой список способов (шаг считался, подходящих нет) — потеря
    'оплата: эхо есть, из списка ушла'  => ['22',   false, null,   true,  'missing_in_response'],
];

foreach ($cases as $name => [$echo, $skipped, $selected, $lost, $reason, $notified]) {
    $r = shopPrefillPluginLostChoiceDetector::decide([
        'echo_id'      => $echo,
        'step_skipped' => $skipped,
        'selected_id'  => $selected,
        'notified_id'  => $notified ?? null,
    ]);
    check($r['lost'] === $lost, "{$name}: lost=" . var_export($lost, true));
    check($r['reason'] === $reason, "{$name}: reason={$reason}");
}

// --- pickDialog() -----------------------------------------------------------------------
$D = shopPrefillPluginLostChoiceDetector::KIND_DELIVERY;
$P = shopPrefillPluginLostChoiceDetector::KIND_PAYMENT;

$dialogs = [
    // [название => [delivery_lost, payment_lost, ожидаемый диалог]]
    'ничего не потеряно'        => [false, false, null],
    'потеряна только доставка'  => [true,  false, $D],
    'потеряна только оплата'    => [false, true,  $P],
    'потеряны обе — про доставку' => [true, true,  $D],
];

foreach ($dialogs as $name => [$d_lost, $p_lost, $expected]) {
    $got = shopPrefillPluginLostChoiceDetector::pickDialog(['lost' => $d_lost], ['lost' => $p_lost]);
    check($got === $expected, "pickDialog, {$name}: " . var_export($expected, true));
}

echo "\n{$checks} проверок, {$failures} провалено\n";
exit($failures > 0 ? 1 : 0);
