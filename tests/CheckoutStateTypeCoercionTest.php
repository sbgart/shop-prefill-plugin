<?php

/**
 * Проверяет фикс issue-95: значения в $params ядром не типизированы, и под скалярным ключом
 * может приехать массив (а под массивным — скаляр). Соседний плагин или тема, отрисовавшая
 * многозначное поле в пространстве имён `auth[data]` / `details[shipping_address]` / `region`,
 * даёт такой POST на обычном /order/calculate/. Без приведения объявленные `: string` / `: array`
 * бросают TypeError, а его не ловит ни waEvent (только Exception), ни render-хуки — покупатель
 * получает 500 вместо формы оформления.
 *
 * Ожидание для всех геттеров: «значения нет» ('' или []), то есть отступление к стоковому
 * чекауту (B2a), а не исключение.
 *
 * Запуск: php tests/CheckoutStateTypeCoercionTest.php
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
 * Вызывает геттер и превращает вылетевший Throwable в текст — тест обязан падать
 * осмысленным сравнением, а не собственным фаталом.
 */
function call(shopPrefillCheckoutState $state, string $method, ...$args)
{
    try {
        return $state->$method(...$args);
    } catch (Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }
}

// -----------------------------------------------------------------------------
// 1. Массив под скалярным ключом: vars.auth.fields — ядро кладёт туда POST как есть
// -----------------------------------------------------------------------------

$params = ['vars' => ['auth' => ['fields' => [
    'firstname' => ['value' => ['injected']],
    'lastname'  => ['value' => ['injected']],
    'phone'     => ['value' => ['injected']],
    'email'     => ['value' => ['injected']],
    'company'   => ['value' => ['injected']],
]]]];
$state = new shopPrefillCheckoutState($params);
check('', call($state, 'getFirstName'), 'getFirstName: массив в vars.auth.fields → пусто');
check('', call($state, 'getLastName'), 'getLastName: массив в vars.auth.fields → пусто');
check('', call($state, 'getPhone'), 'getPhone: массив в vars.auth.fields → пусто');
check('', call($state, 'getEmail'), 'getEmail: массив в vars.auth.fields → пусто');
check('', call($state, 'getCompany'), 'getCompany: массив в vars.auth.fields → пусто');
check(false, call($state, 'hasCustomerIdentityData'), 'hasCustomerIdentityData: мусор не считается данными (гейт Zen не сработает)');

// -----------------------------------------------------------------------------
// 2. Массив под скалярным ключом: сырой POST в data.input.*
// -----------------------------------------------------------------------------

$params = ['data' => ['input' => [
    'auth'    => ['data' => ['firstname' => ['x'], 'phone' => ['x'], 'email' => ['x']]],
    'details' => ['shipping_address' => ['city' => ['x'], 'street' => ['x'], 'building' => ['x'], 'apartment' => ['x'], 'zip' => ['x']]],
    'region'  => ['region' => ['x']],
]]];
$state = new shopPrefillCheckoutState($params);
check('', call($state, 'getFirstName'), 'getFirstName: массив в data.input.auth.data → пусто');
check('', call($state, 'getPhone'), 'getPhone: массив в data.input.auth.data → пусто');
check('', call($state, 'getEmail'), 'getEmail: массив в data.input.auth.data → пусто');
check('', call($state, 'getCity'), 'getCity: массив в details.shipping_address → пусто');
check('', call($state, 'getStreet'), 'getStreet: массив в details.shipping_address → пусто');
check('', call($state, 'getBuilding'), 'getBuilding: массив в details.shipping_address → пусто');
check('', call($state, 'getApartment'), 'getApartment: массив в details.shipping_address → пусто');
check('', call($state, 'getZip'), 'getZip: массив в details.shipping_address → пусто');
check('', call($state, 'getRegion'), 'getRegion: массив в data.input.region → пусто');

// -----------------------------------------------------------------------------
// 3. Массив вместо строки внутри выбранного варианта доставки
// -----------------------------------------------------------------------------

$params = ['data' => ['shipping' => ['selected_variant' => [
    'variant_id'   => ['x'],
    'name'         => ['x'],
    'type'         => ['x'],
    'est_delivery' => ['x'],
    'plugin_name'  => ['x'],
    'service'      => ['x'],
    'description'  => ['x'],
    'rate'         => ['x'],
    'logo'         => ['x'],
    'custom_data'  => 'not-an-array',
]]]];
$state = new shopPrefillCheckoutState($params);
check(null, call($state, 'getShippingVariantId'), 'getShippingVariantId: массив → null');
check(null, call($state, 'getShippingServiceId'), 'getShippingServiceId: массив → null');
check('', call($state, 'getShippingName'), 'getShippingName: массив → пусто');
check('', call($state, 'getShippingType'), 'getShippingType: массив → пусто');
check('', call($state, 'getShippingEstDelivery'), 'getShippingEstDelivery: массив → пусто');
check('', call($state, 'getShippingPluginName'), 'getShippingPluginName: массив → пусто');
check('', call($state, 'getShippingService'), 'getShippingService: массив → пусто');
check('', call($state, 'getShippingDescription'), 'getShippingDescription: массив → пусто');
check(null, call($state, 'getShippingRate'), 'getShippingRate: массив → null');
check(null, call($state, 'getShippingLogoUrl'), 'getShippingLogoUrl: массив → null');
check('', call($state, 'getShippingWay'), 'getShippingWay: custom_data скаляром → пусто');
check('', call($state, 'getShippingStorageDays'), 'getShippingStorageDays: custom_data скаляром → пусто');
check('', call($state, 'getShippingPickupAddress'), 'getShippingPickupAddress: custom_data скаляром → пусто');
check([], call($state, 'getShippingPhotos'), 'getShippingPhotos: custom_data скаляром → пустой массив');

// сам вариант приехал скаляром — ни один потребитель не должен упасть на $variant[...]
$params = ['data' => ['shipping' => ['selected_variant' => 'garbage', 'id' => ['x'], 'custom' => 'garbage']]];
$state = new shopPrefillCheckoutState($params);
check([], call($state, 'getSelectedVariant'), 'getSelectedVariant: скаляр → пустой массив');
check(null, call($state, 'getShippingInstanceId'), 'getShippingInstanceId: массив в data.shipping.id → null');
check([], call($state, 'getShippingCustomFields'), 'getShippingCustomFields: скаляр → пустой массив');

// -----------------------------------------------------------------------------
// 4. Скаляр под массивным ключом — зеркальная подстановка (payment[custom]=x)
// -----------------------------------------------------------------------------

$params = ['data' => [
    'input'   => ['payment' => ['custom' => 'x'], 'auth' => ['data' => 'x'], 'details' => ['shipping_address' => 'x']],
    'payment' => ['id' => ['x']],
    'shipping' => ['address' => 'x'],
    'auth'    => ['delayed_errors' => 'x'],
]];
$state = new shopPrefillCheckoutState($params);
check([], call($state, 'getCustomPaymentFields'), 'getCustomPaymentFields: строка под payment.custom → пустой массив');
check([], call($state, 'getCustomContactFields'), 'getCustomContactFields: строка под auth.data → пустой массив');
check([], call($state, 'getCustomAddressFields'), 'getCustomAddressFields: строка под shipping_address → пустой массив');
check('', call($state, 'getPaymentId'), 'getPaymentId: массив в data.payment.id → пусто');
check('', call($state, 'getPaymentName'), 'getPaymentName: пустой payment_id → пусто, без обращения к провайдеру');
check(null, call($state, 'getPaymentLogoUrl'), 'getPaymentLogoUrl: пустой payment_id → null');
check([], call($state, 'getDelayedErrors', 'auth'), 'getDelayedErrors: строка вместо списка → пустой массив');

// -----------------------------------------------------------------------------
// 5. Служебные поля ответа шага
// -----------------------------------------------------------------------------

$params = ['errors' => 'boom', 'error_step_id' => ['auth'], 'data' => 'boom'];
$state = new shopPrefillCheckoutState($params);
check([], call($state, 'getRegularErrors'), 'getRegularErrors: строка вместо списка ошибок → пустой массив');
check(false, call($state, 'isFastRender'), 'isFastRender: строка вместо списка ошибок → false');
check(null, call($state, 'getErrorStepId'), 'getErrorStepId: массив → null');
check(null, call($state, 'getBlockingGroup'), 'getBlockingGroup: мусор в error_step_id → группа не блокируется');
check([], call($state, 'getData'), 'getData: строка вместо data → пустой массив');

// -----------------------------------------------------------------------------
// 6. Мутация: input приехал скаляром — deepMergeArrays() объявляет `array $base`
// -----------------------------------------------------------------------------

$params = ['data' => ['input' => 'garbage']];
$state = new shopPrefillCheckoutState($params);
check(null, call($state, 'applyPrefillInput', ['auth' => ['data' => ['firstname' => 'Иван']]]), 'applyPrefillInput: скаляр под input → выходим без исключения');
check(false, $state->isPrefilled(), 'applyPrefillInput: предзаполнения не произошло');
check('garbage', $params['data']['input'], 'applyPrefillInput: чужие данные не перезаписаны');

// -----------------------------------------------------------------------------
// 7. Валидные данные проходят насквозь — приведение не должно ничего съесть
// -----------------------------------------------------------------------------

$params = [
    'vars' => ['auth' => ['fields' => ['firstname' => ['value' => 'Иван'], 'phone' => ['value' => '+79000000000']]]],
    'data' => [
        'shipping' => [
            'address' => ['city' => 'Москва', 'street' => 'Тверская', 'region' => '77'],
            'selected_variant' => ['variant_id' => '16.0', 'name' => 'Курьер', 'rate' => '350.5', 'logo' => '/logo.png'],
        ],
        'payment' => ['id' => '16'],
    ],
];
$state = new shopPrefillCheckoutState($params);
check('Иван', call($state, 'getFirstName'), 'happy path: имя из vars.auth.fields');
check('+79000000000', call($state, 'getPhone'), 'happy path: телефон из vars.auth.fields');
check(true, call($state, 'hasCustomerIdentityData'), 'happy path: гейт группы customer видит данные');
check('Москва', call($state, 'getCity'), 'happy path: город из data.shipping.address');
check('77', call($state, 'getRegion'), 'happy path: регион из data.shipping.address');
check('16.0', call($state, 'getShippingVariantId'), 'happy path: variant_id');
check('16', call($state, 'getShippingServiceId'), 'happy path: service_id из variant_id');
check('Курьер', call($state, 'getShippingName'), 'happy path: название тарифа');
check(350.5, call($state, 'getShippingRate'), 'happy path: стоимость доставки приводится к float');
check('/logo.png', call($state, 'getShippingLogoUrl'), 'happy path: логотип доставки');
check('16', call($state, 'getPaymentId'), 'happy path: payment_id');

// число под скалярным ключом — валидное значение, а не мусор
$params = ['data' => ['input' => ['details' => ['shipping_address' => ['building' => 12]]]]];
$state = new shopPrefillCheckoutState($params);
check('12', call($state, 'getBuilding'), 'скаляр не-строка (int) приводится к строке, а не отбрасывается');

echo "\n{$checks} проверок, {$failures} провалено\n";
exit($failures > 0 ? 1 : 0);
