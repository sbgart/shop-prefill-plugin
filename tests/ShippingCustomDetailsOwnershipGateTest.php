<?php

/**
 * Проверяет фикс issue-60: prepareShippingSectionParams() пишет кастомные поля
 * доставки (getShippingCustom()) в чужое пространство имён `order.details.custom`,
 * а не в своё `order.shipping`. Эта запись обязана спрашивать владение `details`
 * отдельно от владения `shipping` — canPrefillSection('shipping') его не проверяет.
 *
 * Три ветки:
 *   1. details свободна   → кастомные поля доставки пишутся (штатный путь)
 *   2. details у покупателя → запись блокируется (сам фикс, P1)
 *   3. applyDeliveryAddress() (без чекера, $can_write_details по умолчанию true)
 *      → пишет всегда — явный выбор покупателя замещает секцию целиком
 *
 * Запуск: php tests/ShippingCustomDetailsOwnershipGateTest.php
 */

if (!class_exists('shopPrefillPluginLog')) {
    class shopPrefillPluginLog
    {
        public static function debug($message, array $context = []): void {}
        public static function info($message, array $context = []): void {}
        public static function warning($message, array $context = []): void {}
        public static function error($message, array $context = []): void {}
    }
}

require_once dirname(__DIR__) . '/lib/classes/fillparams/shopPrefillPluginFillParams.class.php';
require_once dirname(__DIR__) . '/lib/classes/sections/shopPrefillPluginSectionChecker.class.php';
require_once dirname(__DIR__) . '/lib/classes/sessionstorage/shopPrefillPluginSessionStorageProvider.class.php';

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
 * Зовёт private prepareSection() через рефлексию, без конструктора —
 * метод не трогает $this->storage/$this->user_provider, они не нужны.
 */
function callPrepareSection(
    string $section_id,
    shopPrefillPluginFillParams $fill_params,
    array &$final_params,
    shopPrefillPluginSectionChecker $checker,
    array $checkout_params
): void {
    $provider = (new ReflectionClass('shopPrefillPluginSessionStorageProvider'))->newInstanceWithoutConstructor();
    $method   = new ReflectionMethod($provider, 'prepareSection');
    $method->setAccessible(true);
    // invokeArgs (не invoke) сохраняет by-reference семантику третьего параметра,
    // раз args — реальные переменные, а не литералы.
    $method->invokeArgs($provider, [$section_id, $fill_params, &$final_params, $checker, $checkout_params]);
}

function callApplyDeliveryPrepare(shopPrefillPluginFillParams $fill_params, array &$final_params): void
{
    $provider = (new ReflectionClass('shopPrefillPluginSessionStorageProvider'))->newInstanceWithoutConstructor();
    $method   = new ReflectionMethod($provider, 'prepareShippingSectionParams');
    $method->setAccessible(true);
    $method->invokeArgs($provider, [$fill_params, &$final_params]);
}

function fillParamsWithShippingCustom(): shopPrefillPluginFillParams
{
    $fp = new shopPrefillPluginFillParams();
    $fp->setShippingVariantId('5.1');
    $fp->setShippingCustom(['time_interval' => '10-14']);
    return $fp;
}

// Группа delivery включена целиком — проверка идёт по canPrefillSection('details', ...)
$enabled_groups = ['delivery' => true];

// --- Ветка 1: details свободна (нет html) → пишем ---
$checker = new shopPrefillPluginSectionChecker($enabled_groups);
$checkout_params = ['order' => ['shipping' => [], 'details' => []]];
$final_params = [];
callPrepareSection('shipping', fillParamsWithShippingCustom(), $final_params, $checker, $checkout_params);
check('5.1', $final_params['order']['shipping']['variant_id'] ?? null, 'ветка 1: variant_id пишется');
check(['time_interval' => '10-14'], $final_params['order']['details']['custom'] ?? null, 'ветка 1: details.custom пишется, когда details свободна');

// --- Ветка 2: details принадлежит покупателю (html) → НЕ пишем (issue-60 фикс) ---
$checker = new shopPrefillPluginSectionChecker($enabled_groups);
$checkout_params = ['order' => ['shipping' => [], 'details' => ['html' => 'only']]];
$final_params = [];
callPrepareSection('shipping', fillParamsWithShippingCustom(), $final_params, $checker, $checkout_params);
check('5.1', $final_params['order']['shipping']['variant_id'] ?? null, 'ветка 2: variant_id всё равно пишется (своя секция)');
check(false, array_key_exists('details', $final_params['order'] ?? []), 'ветка 2: details.custom НЕ пишется, когда details у покупателя');

// --- Ветка 2b: details свободна, но у покупателя shipping (не должно влиять — не наш гейт) ---
$checker = new shopPrefillPluginSectionChecker($enabled_groups);
$checkout_params = ['order' => ['shipping' => ['html' => 'only'], 'details' => []]];
$final_params = [];
callPrepareSection('shipping', fillParamsWithShippingCustom(), $final_params, $checker, $checkout_params);
check(['time_interval' => '10-14'], $final_params['order']['details']['custom'] ?? null, 'ветка 2b: владение shipping не блокирует запись в details (гейты независимы)');

// --- Ветка 3: applyDeliveryAddress() — явный выбор, гейта нет, пишет всегда ---
$final_params = [];
callApplyDeliveryPrepare(fillParamsWithShippingCustom(), $final_params);
check(['time_interval' => '10-14'], $final_params['order']['details']['custom'] ?? null, 'ветка 3: applyDeliveryAddress пишет details.custom без чекера (явный выбор)');

// --- Без кастомных полей в источнике — details вообще не появляется в final_params ---
$checker = new shopPrefillPluginSectionChecker($enabled_groups);
$checkout_params = ['order' => ['shipping' => [], 'details' => []]];
$final_params = [];
$fp_no_custom = new shopPrefillPluginFillParams();
$fp_no_custom->setShippingVariantId('5.1');
callPrepareSection('shipping', $fp_no_custom, $final_params, $checker, $checkout_params);
check(false, array_key_exists('details', $final_params['order'] ?? []), 'без shipping_custom в источнике details не трогается');

echo "\n{$checks} проверок, {$failures} провалено\n";
exit($failures > 0 ? 1 : 0);
