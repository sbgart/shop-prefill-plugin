<?php

/**
 * Предупреждение о пропавшем выборе (P10): ворота-опция `zen.lost_choice_notice` и детекция
 * потери оплаты при `payment[html]=only`.
 *
 * Тест гоняет настоящий `renderLostChoiceScript()` хука подтверждения (через рефлексию, без
 * конструктора и Webasyst), а не его копию: ворота, статика-память списка способов и решение
 * детектора проверяются вместе, как в бою.
 *
 * Ворота. Функция новая, магазин включает её сам (по умолчанию выключена). Выключенная опция
 * обязана быть невидимой: ни диалога, ни строк в логе, ни единой записи в сессию (P5 —
 * лишняя запись поднимает `Set-Cookie: PHPSESSID` анониму).
 *
 * Оплата. Ядро при `html=only` вырезает `methods` ПОСЛЕ того, как хук `checkout_render_payment`
 * отработал, а решение принимает хук подтверждения. Хук оплаты запоминает список (статика:
 * объект плагина ядро пересоздаёт на каждое событие), хук подтверждения его читает.
 * Без этого диалог потери оплаты приходил на запрос позже
 * (docs/bugs/done/lost-payment-dialog-delayed-by-html-only.md).
 *
 * Мутации, каждая обязана дать провал:
 *   - убрать ворота из renderLostChoiceScript() (диалог при выключенной опции);
 *   - убрать ворота из handleCheckoutRenderPayment() (запоминание при выключенной опции);
 *   - убрать rememberPaymentMethodIds() из хука оплаты (диалог оплаты снова на запрос позже);
 *   - читать в getPaymentMethodIds() только параметры хука (то же);
 *   - не сбрасывать память между запросами тестом (в бою статика пуста сама — тест это ловит
 *     как утечку между сценариями);
 *   - ворота читают не тот ключ настройки.
 *
 * Запуск: php tests/LostChoiceNoticeGateTest.php
 */

class waException extends Exception
{
}

/** Хранилище сессии с журналом записей. */
class waSessionStorage
{
    public array $data = [];
    /** @var string[] */
    public array $writes = [];

    /** @return mixed */
    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /** @param mixed $value */
    public function set(string $key, $value): void
    {
        $this->writes[] = "set:{$key}";
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->writes[] = "remove:{$key}";
        unset($this->data[$key]);
    }
}

/** Лог-заглушка: считает записи, чтобы проверить «выключено — молчим». */
class shopPrefillPluginLog
{
    public static int $entries = 0;

    public static function debug($message, $context = []): void { self::$entries++; }
    public static function info($message, $context = []): void { self::$entries++; }
    public static function warning($message, $context = []): void { self::$entries++; }
    public static function error($message, $context = []): void { self::$entries++; }
}

require_once dirname(__DIR__) . '/lib/classes/checkout/shopPrefillPluginLostChoiceDetector.class.php';
require_once dirname(__DIR__) . '/lib/classes/checkout/shopPrefillCheckoutState.class.php';
require_once dirname(__DIR__) . '/lib/classes/sessionstorage/shopPrefillPluginSessionStorageProvider.class.php';
require_once dirname(__DIR__) . '/lib/classes/hooks/shopPrefillPluginCheckoutHooks.class.php';

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

/** Провайдер сессии без конструктора, с подставленным хранилищем. */
function makeSession(waSessionStorage $storage, array $delivery_echo = null, array $payment_echo = null): shopPrefillPluginSessionStorageProvider
{
    $provider = (new ReflectionClass('shopPrefillPluginSessionStorageProvider'))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty($provider, 'storage');
    $property->setAccessible(true);
    $property->setValue($provider, $storage);

    if ($delivery_echo !== null) {
        $storage->data['shop/prefill_delivery_echo'] = $delivery_echo;
    }
    if ($payment_echo !== null) {
        $storage->data['shop/prefill_payment_echo'] = $payment_echo;
    }

    return $provider;
}

/** Хук без конструктора: только то, чем пользуются проверяемые методы. */
function makeHooks(?bool $notice, shopPrefillPluginSessionStorageProvider $session): shopPrefillPluginCheckoutHooks
{
    $hooks = (new ReflectionClass('shopPrefillPluginCheckoutHooks'))->newInstanceWithoutConstructor();

    $settings = ['active' => true, 'zen' => []];
    if ($notice !== null) {
        $settings['zen']['lost_choice_notice'] = $notice;
    }

    foreach (['storefront_settings' => $settings, 'session_storage' => $session] as $name => $value) {
        $property = new ReflectionProperty($hooks, $name);
        $property->setAccessible(true);
        $property->setValue($hooks, $value);
    }

    return $hooks;
}

function callPrivate(object $object, string $method, ...$args)
{
    $m = new ReflectionMethod($object, $method);
    $m->setAccessible(true);

    return $m->invoke($object, ...$args);
}

/** Состояние хука подтверждения: vars после ошибки доставки «варианта нет» и списка способов оплаты. */
function confirmState(array $vars, ?string $selected_variant = null): shopPrefillCheckoutState
{
    // Выбранный вариант хук читает из data.shipping.selected_variant (не из vars) — как в ответе ядра
    $params = ['vars' => $vars];
    if ($selected_variant !== null) {
        $params['data'] = ['shipping' => ['selected_variant' => ['variant_id' => $selected_variant]]];
    }

    return new shopPrefillCheckoutState($params);
}

// Живые формы vars (гость, 04.10.2026)
$shipping_lost = ['selected_type_id' => 'post', 'selected_variant_id' => null, 'map' => [], 'html' => '<div/>'];
$shipping_ok   = ['selected_type_id' => 'todoor', 'selected_variant_id' => '33.courier', 'map' => [], 'html' => '<div/>'];

// --- 1. Ворота: выключено или не задано — тишина --------------------------------------------

foreach ([['опция выключена', false], ['ключа в настройках нет (витрина без значения)', null]] as [$title, $notice]) {
    shopPrefillCheckoutState::forgetSeenPaymentMethodIds();
    shopPrefillPluginLog::$entries = 0;

    $storage = new waSessionStorage();
    $session = makeSession($storage, ['variant_id' => '36.parcel', 'custom' => [], 'region' => []], ['id' => '22']);
    $hooks   = makeHooks($notice, $session);

    // Настоящая потеря и доставки, и оплаты: эхо есть, шаг посчитан, выбора в ответе нет
    $state  = confirmState(['shipping' => $shipping_lost, 'payment' => ['selected_method_id' => '', 'methods' => [20 => []], 'html' => '']]);
    $writes = count($storage->writes);
    $out    = callPrivate($hooks, 'renderLostChoiceScript', $state);

    check('', $out, "{$title}: диалога нет, хотя выбор потерян");
    check($writes, count($storage->writes), "{$title}: ни одной записи в сессию (P5)");
    check(0, shopPrefillPluginLog::$entries, "{$title}: ни одной строки в логе");
}

// --- 2. Включено: потеря доставки сообщается -------------------------------------------------

shopPrefillCheckoutState::forgetSeenPaymentMethodIds();
$storage = new waSessionStorage();
$session = makeSession($storage, ['variant_id' => '36.parcel', 'custom' => [], 'region' => []]);
$hooks   = makeHooks(true, $session);
$out     = callPrivate($hooks, 'renderLostChoiceScript', confirmState(['shipping' => $shipping_lost]));
check(true, strpos($out, 'prefill_delivery_lost') !== false, 'опция включена: потеря доставки → событие prefill_delivery_lost');

// Здоровый запрос при включённой опции — тишина
$storage = new waSessionStorage();
$session = makeSession($storage, ['variant_id' => '33.courier', 'custom' => [], 'region' => []], ['id' => '20']);
$out     = callPrivate(makeHooks(true, $session), 'renderLostChoiceScript', confirmState([
    'shipping' => $shipping_ok,
    'payment'  => ['selected_method_id' => '20', 'methods' => [20 => []], 'html' => ''],
], '33.courier'));
check('', $out, 'опция включена, выбор на месте: диалога нет');

// --- 3. Оплата при html=only: список видит хук оплаты, решает хук подтверждения -------------

/**
 * Запрос смены типа доставки, где способ 22 пропал, а ядро при html=only вырезало methods.
 *
 * @param bool|null $notice          значение опции
 * @param bool      $payment_hook_ran сработал ли хук оплаты (иначе methods не видел никто)
 */
function paymentLostRequest(?bool $notice, bool $payment_hook_ran, array $confirm_vars = null): string
{
    shopPrefillCheckoutState::forgetSeenPaymentMethodIds();

    $storage = new waSessionStorage();
    $session = makeSession($storage, null, ['id' => '22']);
    $hooks   = makeHooks($notice, $session);

    if ($payment_hook_ran) {
        // Хук оплаты: ядро ещё НЕ вырезало список; 22 в нём уже нет
        $render = confirmState(['payment' => ['selected_method_id' => '22', 'methods' => [16 => [], 17 => [], 20 => [], 23 => []], 'html' => '']]);
        callPrivate($hooks, 'rememberPaymentMethodsForConfirm', $render);
    }

    // Хук подтверждения: methods уже вырезан (html=only), остался только признак посчитанного шага
    $confirm = confirmState($confirm_vars ?? [
        'shipping' => ['selected_type_id' => 'post', 'selected_variant_id' => '36.parcel', 'map' => [], 'html' => ''],
        'payment'  => ['selected_method_id' => '22', 'html' => ''],
    ], '36.parcel');

    return callPrivate($hooks, 'renderLostChoiceScript', $confirm);
}

check(true, strpos(paymentLostRequest(true, true), 'prefill_payment_lost') !== false, 'html=only: хук оплаты видел список без 22 → диалог потери оплаты в ТОМ ЖЕ запросе');
check('', paymentLostRequest(true, false), 'хук оплаты не сработал, список не видел никто → тишина (B2a)');
check('', paymentLostRequest(false, true), 'опция выключена → тишина, даже когда хук оплаты видел список');

// Память не должна протекать между запросами и между сценариями
shopPrefillCheckoutState::forgetSeenPaymentMethodIds();
check(null, confirmState([])->getPaymentMethodIds(), 'после сброса памяти списка нет');

// Выключенная опция не запоминает ничего
shopPrefillCheckoutState::forgetSeenPaymentMethodIds();
$hooks = makeHooks(false, makeSession(new waSessionStorage()));
callPrivate($hooks, 'rememberPaymentMethodsForConfirm', confirmState(['payment' => ['methods' => [20 => []]]]));
check(null, confirmState([])->getPaymentMethodIds(), 'опция выключена: список не запоминается');

// Включённая запоминает и отдаёт хуку, у которого списка нет
shopPrefillCheckoutState::forgetSeenPaymentMethodIds();
$hooks = makeHooks(true, makeSession(new waSessionStorage()));
callPrivate($hooks, 'rememberPaymentMethodsForConfirm', confirmState(['payment' => ['methods' => [20 => [], 22 => []]]]));
check(['20', '22'], confirmState([])->getPaymentMethodIds(), 'опция включена: список (строками) доступен хуку подтверждения');
check(['16'], confirmState(['payment' => ['methods' => [16 => []]]])->getPaymentMethodIds(), 'собственный список хука важнее запомненного');

// --- 4. Оплата и ошибка выше по форме: ложной тревоги нет -------------------------------------

check(
    '',
    paymentLostRequest(true, false, ['shipping' => ['html' => ''], 'payment' => ['html' => '']]),
    'ошибка auth/region выше: ни доставка, ни оплата не считались → тишина'
);

// --- 5. Оба выбора потеряны: один диалог, доставка главнее -----------------------------------

shopPrefillCheckoutState::forgetSeenPaymentMethodIds();
$storage = new waSessionStorage();
$session = makeSession($storage, ['variant_id' => '36.parcel', 'custom' => [], 'region' => []], ['id' => '22']);
$hooks   = makeHooks(true, $session);
callPrivate($hooks, 'rememberPaymentMethodsForConfirm', confirmState(['payment' => ['methods' => [20 => []]]]));
$out = callPrivate($hooks, 'renderLostChoiceScript', confirmState(['shipping' => $shipping_lost, 'payment' => ['selected_method_id' => '22', 'html' => '']]));
check(true, strpos($out, 'prefill_delivery_lost') !== false && strpos($out, 'prefill_payment_lost') === false, 'потеряны оба: один диалог, про доставку');
check('36.parcel', $session->getLostChoiceNotified('delivery'), 'о доставке предупреждены (отметка)');
check('22', $session->getLostChoiceNotified('payment'), 'об оплате тоже предупреждены, чтобы она не всплыла вторым диалогом');

// --- 6. Хук оплаты действительно зовёт запоминание -------------------------------------------

$source = file_get_contents(dirname(__DIR__) . '/lib/classes/hooks/shopPrefillPluginCheckoutHooks.class.php');
preg_match('/function handleCheckoutRenderPayment\(.*?\n    }\n/s', $source, $m);
check(true, isset($m[0]) && strpos($m[0], 'rememberPaymentMethodsForConfirm($state)') !== false, 'handleCheckoutRenderPayment() вызывает rememberPaymentMethodsForConfirm()');
preg_match('/function rememberPaymentMethodsForConfirm\(.*?\n    }\n/s', $source, $m);
check(true, isset($m[0]) && strpos($m[0], 'isLostChoiceNoticeEnabled()') !== false && strpos($m[0], 'rememberPaymentMethodIds()') !== false, 'запоминание стоит за опцией');
preg_match('/function isLostChoiceNoticeEnabled\(.*?\n    }\n/s', $source, $m);
check(true, isset($m[0]) && strpos($m[0], "['zen']['lost_choice_notice']") !== false, 'ворота читают zen.lost_choice_notice');

echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
