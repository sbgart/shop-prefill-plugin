<?php

/**
 * Гейт группы `customer` спрашивает источник сводки, а не сессию чекаута.
 *
 * Замок на f01 (docs/bugs/zen-customer-group-never-collapses-f01.md): минимум группы
 * `customer` считался по `order.auth.data.*` из `shop/checkout`, куда данные авторизованного
 * покупателя не пишутся вовсе — предзаполнение auth-секцию для него сознательно пропускает,
 * а из POST они приходят только после первого рендера. Гейт отказывал на первом кадре каждой
 * новой сессии, хотя форма была заполнена и сводка отрисовалась бы верно.
 *
 * Ключевая проверка — №5: сессия пуста, `$state` полон → «заполнено». Именно она падала бы
 * на старой реализации.
 *
 * Проверяется и то, что фикс НЕ трогает `delivery`/`payment`: у них `$params` реально пустеет
 * при коротком замыкании и `fast_render` (Z5, P9), поэтому их источник остаётся сессионным.
 *
 * isGroupMinimumFilled() приватный и обращается к session_storage только в несобственной
 * ветке — вызывается через reflection на инстансе без конструктора, чтобы не поднимать
 * Webasyst-зависимости (waResponse/waView/... ), как в ZenGroupCarrierTest.
 *
 * Запуск: php tests/ZenCustomerGateSourceTest.php
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
        return;
    }
    echo "ok: {$message}\n";
}

/**
 * Состояние рендера с заданными полями секции auth (как их отдаёт ядро в vars.auth.fields).
 *
 * @param array<string, string> $fields Пары поле => значение
 */
function stateWithAuthFields(array $fields): shopPrefillCheckoutState
{
    $vars = [];
    foreach ($fields as $name => $value) {
        $vars[$name] = ['value' => $value];
    }

    // Конструктор принимает $params по ссылке — нужна переменная, а не литерал
    $params = ['vars' => ['auth' => ['fields' => $vars]]];

    return new shopPrefillCheckoutState($params);
}

/** Состояние, где шаг auth не отработал: vars.auth === [] (короткое замыкание / fast_render). */
function stateWithSkippedAuth(): shopPrefillCheckoutState
{
    $params = ['vars' => ['auth' => []]];

    return new shopPrefillCheckoutState($params);
}

/** Состояние, где данные пришли только через предзаполнение (data.input), без vars. */
function stateWithPrefillInput(array $auth_data): shopPrefillCheckoutState
{
    $params = ['data' => ['input' => ['auth' => ['data' => $auth_data]]]];

    return new shopPrefillCheckoutState($params);
}

/** Вызов приватного isGroupMinimumFilled() без поднятия конструктора плагина. */
function minimumFilled(string $group, shopPrefillCheckoutState $state): bool
{
    $reflection = new ReflectionClass('shopPrefillPluginZenMode');
    $zen_mode   = $reflection->newInstanceWithoutConstructor();

    $method = $reflection->getMethod('isGroupMinimumFilled');
    $method->setAccessible(true);

    return $method->invoke($zen_mode, $group, $state);
}

// --- 1. Гость: поля секции отрисованы, но пустые — сворачивать нечего (Z2) ---
check(
    false,
    minimumFilled('customer', stateWithAuthFields([
        'firstname' => '',
        'lastname'  => '',
        'phone'     => '',
        'email'     => '',
    ])),
    'customer: гость с пустой формой — минимум не заполнен'
);

// --- 2. Авторизованный: ядро подставило значения контакта — сворачивать есть что ---
check(
    true,
    minimumFilled('customer', stateWithAuthFields([
        'firstname' => 'Admin',
        'lastname'  => 'Admin',
        'phone'     => '+7 (923) 111-22-33',
        'email'     => 'admin@example.com',
    ])),
    'customer: заполненная форма авторизованного — минимум заполнен'
);

// --- 3. Минимум срабатывает по любому одному из трёх полей ---
check(
    true,
    minimumFilled('customer', stateWithAuthFields(['phone' => '+7 (923) 111-22-33'])),
    'customer: только телефон — минимум заполнен'
);
check(
    true,
    minimumFilled('customer', stateWithAuthFields(['email' => 'admin@example.com'])),
    'customer: только email — минимум заполнен'
);

// --- 4. lastname и company в минимум НЕ входят: набор тот же, что у SECTION_DATA_FIELDS ---
// Щит от «улучшения» до всех полей сводки — это меняло бы Z4 отдельно от источника.
check(
    false,
    minimumFilled('customer', stateWithAuthFields(['lastname' => 'Admin'])),
    'customer: только фамилия — минимум НЕ заполнен (набор полей не расширять)'
);
check(
    false,
    minimumFilled('customer', stateWithAuthFields(['company' => 'ООО «Ромашка»'])),
    'customer: только компания — минимум НЕ заполнен'
);

// --- 5. РЕПРО f01: сессия пуста, состояние рендера полно ---
// На старой реализации (проверка по shop/checkout) здесь был бы false — и группа
// не сворачивалась на первом кадре каждой новой сессии авторизованного покупателя.
check(
    true,
    minimumFilled('customer', stateWithAuthFields([
        'firstname' => 'Admin',
        'phone'     => '+7 (923) 111-22-33',
        'email'     => 'admin@example.com',
    ])),
    'customer: репро f01 — сессия чекаута пуста, но vars.auth.fields заполнены → минимум заполнен'
);

// --- 6. Предзаполнение через data.input тоже считается ---
// Геттеры состояния падают на data.input.auth.data, когда ядро ещё не отрисовало vars —
// это путь гостя с историей, которому предзаполнение положило auth в input.
check(
    true,
    minimumFilled('customer', stateWithPrefillInput(['phone' => '+7 (923) 111-22-33'])),
    'customer: значение из data.input.auth.data — минимум заполнен'
);

// --- 7. Гард: шаг auth не отработал — это неопределённость, а не пустота (B2a) ---
// Уходим в сессионную ветку. Без session_storage она бросит Error, и это ровно то,
// что нужно проверить: гейт НЕ ответил «данных нет», а пошёл к прежнему источнику.
$fell_through = false;
try {
    minimumFilled('customer', stateWithSkippedAuth());
} catch (Throwable $e) {
    $fell_through = true;
}
check(true, $fell_through, 'customer: vars.auth === [] — гейт отступает на сессионную ветку, а не отвечает «пусто»');

// --- 8. delivery и payment фикс не трогает: их источник остаётся сессионным ---
$delivery_uses_session = false;
try {
    minimumFilled('delivery', stateWithAuthFields(['firstname' => 'Admin']));
} catch (Throwable $e) {
    $delivery_uses_session = true;
}
check(true, $delivery_uses_session, 'delivery: источник по-прежнему сессия, а не $state');

$payment_uses_session = false;
try {
    minimumFilled('payment', stateWithAuthFields(['firstname' => 'Admin']));
} catch (Throwable $e) {
    $payment_uses_session = true;
}
check(true, $payment_uses_session, 'payment: источник по-прежнему сессия, а не $state');

// --- 9. Геттер состояния сам по себе ---
check(
    false,
    stateWithAuthFields([])->hasCustomerIdentityData(),
    'hasCustomerIdentityData(): пустое состояние — false'
);
check(
    true,
    stateWithAuthFields(['firstname' => 'Admin'])->hasCustomerIdentityData(),
    'hasCustomerIdentityData(): есть имя — true'
);

echo "\n{$checks} проверок, {$failures} провалено\n";
exit($failures > 0 ? 1 : 0);
