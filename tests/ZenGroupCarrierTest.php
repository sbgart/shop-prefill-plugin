<?php

/**
 * Проверяет карту «группа → секция-носитель» (shopPrefillPluginZenMode::GROUP_CARD_SECTION)
 * и CSS, который она генерирует — docs/bugs/payment-zen-card-vanishes-on-shortcircuit.md, B2b.
 *
 * Структурный замок: связь группы с секцией, из которой рендерится её карточка, до этого
 * жила только в том, из какого checkout_render_* хука вызывается buildCollapseBlock() —
 * неявно и без защиты. Теперь это константа, и тест проверяет её форму, а не конкретные
 * значения: делать это дословно на строке «delivery => details» — щит от «упрощения»
 * до end(GROUP_SECTIONS['delivery']), которое выглядело бы эквивалентным, но развязало бы
 * константу с реальным хуком (см. «Отвергнутые варианты», п. 4 в
 * docs/bugs/zen-collapse-on-upstream-checkout-error.md).
 *
 * generateGroupStyles() и generateSectionRevealStyles() не используют $this — вызываются
 * через reflection на инстансе без конструктора, чтобы не поднимать Webasyst-зависимости
 * (waResponse/waView/... в конструкторе shopPrefillPluginZenMode).
 *
 * Запуск: php tests/ZenGroupCarrierTest.php
 */

require_once dirname(__DIR__) . '/lib/classes/checkout/shopPrefillCheckoutState.class.php';
require_once dirname(__DIR__) . '/lib/classes/zenmode/shopPrefillPluginZenMode.class.php';

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

function callPrivate(string $method, array $args)
{
    $instance = (new ReflectionClass('shopPrefillPluginZenMode'))->newInstanceWithoutConstructor();
    $ref = new ReflectionMethod('shopPrefillPluginZenMode', $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($instance, $args);
}

function generateGroupStyles(string $group): string
{
    return callPrivate('generateGroupStyles', [$group]);
}

function generateSectionRevealStyles(string $group, shopPrefillCheckoutState $state): string
{
    return callPrivate('generateSectionRevealStyles', [$group, $state]);
}

function stateWithVars(array $vars): shopPrefillCheckoutState
{
    $params = ['vars' => $vars];
    return new shopPrefillCheckoutState($params);
}

// --- 1. Структурный замок: у каждой группы объявлен носитель ---
check(
    array_keys(shopPrefillPluginZenMode::GROUP_SECTIONS),
    array_keys(shopPrefillPluginZenMode::GROUP_CARD_SECTION),
    'GROUP_CARD_SECTION объявлен для каждой группы из GROUP_SECTIONS'
);

// --- 2. Носитель — всегда секция своей же группы ---
foreach (shopPrefillPluginZenMode::GROUP_SECTIONS as $group => $sections) {
    $carrier = shopPrefillPluginZenMode::GROUP_CARD_SECTION[$group] ?? null;
    check(true, in_array($carrier, $sections, true), "носитель группы '{$group}' входит в её же GROUP_SECTIONS");
}

// --- 3. Носитель delivery — дословно 'details' ---
// Не end(GROUP_SECTIONS['delivery']): это архитектурное решение (checkout_render_details
// срабатывает безусловно даже при коротком замыкании), а не совпадение позиции в массиве.
check('details', shopPrefillPluginZenMode::GROUP_CARD_SECTION['delivery'], 'носитель delivery — details, не region/shipping');

// --- 4. Скрывающий CSS: ровно по одному правилу на секцию группы, никаких "display: block" ---
$css = generateGroupStyles('payment');
check(1, substr_count($css, 'display: none'), 'generateGroupStyles(payment): одно скрывающее правило');
check(0, substr_count($css, 'display: block'), 'generateGroupStyles(payment): без показывающих правил');

$css = generateGroupStyles('delivery');
check(3, substr_count($css, 'display: none'), 'generateGroupStyles(delivery): по правилу на каждую из 3 секций группы');
check(0, substr_count($css, 'display: block'), 'generateGroupStyles(delivery): без показывающих правил');

check('', generateGroupStyles('unknown-group'), 'generateGroupStyles(неизвестная группа) — пустая строка');

// --- 5. Показывающий CSS: срабатывает только когда носитель пуст ---
check(
    '',
    generateSectionRevealStyles('payment', stateWithVars(['payment' => ['methods' => ['16' => ['id' => '16']]]])),
    'generateSectionRevealStyles(payment): способы посчитаны — правило не эмитится'
);
check(
    '',
    generateSectionRevealStyles('payment', stateWithVars(['payment' => ['disabled' => true]])),
    'generateSectionRevealStyles(payment): оплата осознанно выключена — правило не эмитится'
);

$reveal = generateSectionRevealStyles('payment', stateWithVars(['payment' => []]));
check(true, strpos($reveal, '.wa-step-payment-section { display: block !important; }') !== false, 'generateSectionRevealStyles(payment): шаг не считался — правило раскрывает секцию-носитель');
check(1, substr_count($reveal, 'display: block'), 'generateSectionRevealStyles(payment): ровно одно показывающее правило');

// --- 6. Регресс-щит: раскрытие delivery не задевает region/shipping (там core-заголовок) ---
$reveal = generateSectionRevealStyles('delivery', stateWithVars(['details' => []]));
check(1, substr_count($reveal, 'display: block'), 'generateSectionRevealStyles(delivery): ровно одно показывающее правило — только носитель');
check(false, strpos($reveal, '.wa-step-region-section {') !== false, 'generateSectionRevealStyles(delivery): region не раскрывается (там безусловный core-заголовок)');
check(false, strpos($reveal, '.wa-step-shipping-section {') !== false, 'generateSectionRevealStyles(delivery): shipping не раскрывается');
check(true, strpos($reveal, '.wa-step-details-section { display: block !important; }') !== false, 'generateSectionRevealStyles(delivery): details (носитель) раскрыт');

echo "\n{$checks} проверок, {$failures} провалено\n";
exit($failures > 0 ? 1 : 0);
