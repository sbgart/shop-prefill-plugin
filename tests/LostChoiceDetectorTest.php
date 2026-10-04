<?php

/**
 * Таблица истинности детектора потери выбранного варианта доставки.
 *
 * Мутации, каждая обязана дать провал:
 *   1. убрать ветку step_skipped — на каждой загрузке /order/ (fast_render) пойдёт «потеряно»;
 *   2. убрать ветку «нет эха» — «потеряно» у покупателя, который ничего не выбирал;
 *   3. считать потерей смену варианта на другой, а не пропажу.
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

$cases = [
    // [название, echo, step_skipped, selected, lost, reason]
    'эха нет, выбора нет'              => [null,    false, null,    false, 'nothing_chosen'],
    'эхо пустой строкой'               => ['',      false, null,    false, 'nothing_chosen'],
    'эха нет, fast_render'             => [null,    true,  null,    false, 'nothing_chosen'],
    'эхо есть, fast_render'            => ['33.c',  true,  null,    false, 'step_skipped'],
    'эхо есть, шаг не считался'        => ['33.c',  true,  '',      false, 'step_skipped'],
    'эхо есть, вариант пропал (null)'  => ['33.c',  false, null,    true,  'variant_missing_in_response'],
    'эхо есть, вариант пропал (пусто)' => ['33.c',  false, '',      true,  'variant_missing_in_response'],
    'эхо есть, вариант на месте'       => ['33.c',  false, '33.c',  false, 'variant_kept'],
    'эхо есть, выбран другой'          => ['33.c',  false, '34.d',  false, 'variant_kept'],
];

foreach ($cases as $name => [$echo, $skipped, $selected, $lost, $reason]) {
    $r = shopPrefillPluginLostChoiceDetector::decide([
        'echo_variant_id'     => $echo,
        'step_skipped'        => $skipped,
        'selected_variant_id' => $selected,
    ]);
    check($r['lost'] === $lost, "{$name}: lost=" . var_export($lost, true));
    check($r['reason'] === $reason, "{$name}: reason={$reason}");
}

echo "\n{$checks} проверок, {$failures} провалено\n";
exit($failures > 0 ? 1 : 0);
