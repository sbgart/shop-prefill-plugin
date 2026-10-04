<?php

/**
 * Запирает порядок проверок в решении о сворачивании группы
 * (shopPrefillPluginZenMode::decideCollapse) и протокол куки между сервером и клиентом.
 *
 * Зачем. 20.09.2026 владелец нашёл руками дефект, которого не видел ни один прогон: блок,
 * свёрнутый карточкой, исчезал с экрана целиком, если нажать «Изменить» после того, как
 * данные выше сломаны, но форма ещё не перерисована. Лечится веткой «просьбу развернуть,
 * которую некуда применить, отклоняем» — и вся правка держится на том, что эта ветка стоит
 * ПЕРЕД веткой защёлки. Порядок веток раньше нельзя было проверить вовсе: решение тянуло
 * защёлку, настройки и сессию. Теперь решение — чистая функция, а это её таблица истинности.
 *
 * Тест обязан уметь падать. Мутации, каждая обязана дать провал:
 *   1. перенести ветку отказа под ветку защёлки;
 *   2. заменить условие `blocked` на «шаг не считался» (isStepSkipped) — тогда отказ
 *      прилетал бы на каждой загрузке /order/, где fast_render;
 *   3. забыть EXPAND_REQUEST в ZenLatch::isExpanded() — обычный разворот перестанет работать;
 *   4. разойтись значениями куки между PHP и JS.
 *
 * См. docs/plans/zen-blocked-group-feedback.md,
 *     docs/bugs/done/zen-block-vanishes-on-stale-blocked-flag.md
 *
 * Запуск: php tests/ZenCollapseDecisionTest.php
 */

require_once dirname(__DIR__) . '/lib/classes/checkout/shopPrefillCheckoutState.class.php';
require_once dirname(__DIR__) . '/lib/classes/zenmode/shopPrefillPluginZenMode.class.php';
require_once dirname(__DIR__) . '/lib/classes/zenmode/shopPrefillPluginZenLatch.class.php';

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

/**
 * @param array<string, bool> $facts Частичный набор фактов, остальные — «ничего не мешает свернуть»
 * @return array{collapsed: bool, reason: string}
 */
function decide(array $facts): array
{
    return shopPrefillPluginZenMode::decideCollapse($facts + [
        'expand_requested' => false,
        'blocked'          => false,
        'latch_expanded'   => false,
        'has_errors'       => false,
        'minimum_filled'   => true,
    ]);
}

// --- Главное: просьба, которую некуда применить, отклоняется раньше всех остальных веток ---

$d = decide(['expand_requested' => true, 'blocked' => true, 'latch_expanded' => true]);
check($d['collapsed'] === true, 'просьба развернуть при блокировке: группа остаётся свёрнутой');
check($d['reason'] === 'expand_refused_blocked', 'причина отказа названа явно (для лога и debug-панели)');

$d = decide(['expand_requested' => true, 'blocked' => true, 'latch_expanded' => true, 'has_errors' => true, 'minimum_filled' => false]);
check($d['reason'] === 'expand_refused_blocked', 'отказ сильнее ошибок группы и нехватки данных');

// --- Случай 2 правила: группу, развёрнутую ДО поломки, блокировка не трогает ---

$d = decide(['expand_requested' => false, 'blocked' => true, 'latch_expanded' => true]);
check($d['collapsed'] === false, 'развёрнутая раньше группа при блокировке остаётся развёрнутой');
check($d['reason'] === 'expanded_by_user', 'и причина у неё прежняя — защёлка покупателя');

// --- Свёрнутая группа при блокировке: сводка остаётся на экране ---

$d = decide(['blocked' => true, 'minimum_filled' => true]);
check($d['collapsed'] === true, 'свёрнутая группа при блокировке свёрнутой и остаётся');
check($d['reason'] === 'minimum_filled', 'без выдуманных причин: данные есть, блокировка её не касается');

// --- Обычный разворот: просьба без блокировки срабатывает ---

$d = decide(['expand_requested' => true, 'blocked' => false, 'latch_expanded' => true]);
check($d['collapsed'] === false, 'просьба развернуть на исправной форме разворачивает группу');
check($d['reason'] === 'expanded_by_user', 'применённая просьба неотличима от обычной защёлки');

// --- Остальные ветки не задеты ---

$d = decide(['has_errors' => true]);
check($d['collapsed'] === false && $d['reason'] === 'validation_errors', 'ошибки группы разворачивают её (Z1)');

$d = decide(['minimum_filled' => false]);
check($d['collapsed'] === false && $d['reason'] === 'minimum_not_filled', 'нечего сводить — не сворачиваем (Z2)');

$d = decide([]);
check($d['collapsed'] === true && $d['reason'] === 'minimum_filled', 'ничего не мешает — сворачиваем');

$d = decide(['latch_expanded' => true, 'has_errors' => true]);
check($d['reason'] === 'expanded_by_user', 'защёлка сильнее ошибок: группа не закрывается под руками (Z4)');

// --- Блокировка сама по себе ничего не решает: только вместе с просьбой ---

$d = decide(['blocked' => true, 'minimum_filled' => false]);
check($d['reason'] === 'minimum_not_filled', 'блокировка без просьбы не подменяет остальные причины');

// --- Протокол куки: сервер и клиент обязаны знать одно значение ---

$js = file_get_contents(dirname(__DIR__) . '/js/modules/ZenModeToggle.js');
$value = shopPrefillPluginZenLatch::EXPAND_REQUEST;

check($value !== 'expanded', 'значение просьбы не совпадает с состоянием: опечаткой не спутать');
check(strpos($js, '"=' . $value . '; path=/') !== false, "клиент пишет ровно '{$value}' (значение из PHP-константы)");
check(strpos($js, '"=expanded; path=/') === false, 'клиент больше не пишет состояние напрямую');

// Структурный замок на мутацию 3: isExpanded() обязана признавать просьбу, иначе обычный
// разворот перестанет работать. Проверить вызовом нельзя — метод тянет waRequest, которого
// вне фреймворка нет, поэтому смотрим на текст метода (приём из ZenGroupCarrierTest).
$latch_src = file_get_contents(dirname(__DIR__) . '/lib/classes/zenmode/shopPrefillPluginZenLatch.class.php');
preg_match('/function isExpanded\(.*?\n    \}/s', $latch_src, $m);
check(!empty($m) && strpos($m[0], 'EXPAND_REQUEST') !== false, 'isExpanded() признаёт просьбу наравне с состоянием');

echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
