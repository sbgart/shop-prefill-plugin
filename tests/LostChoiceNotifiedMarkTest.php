<?php

/**
 * Жизненный цикл отметки «о потере этого выбора покупателя уже предупредили» (P10) и
 * способ оплаты в списке посчитанных (`getPaymentMethodIds()`).
 *
 * Отметка держит диалог «Нужно выбрать другую доставку / оплату» разовым. Мёртвый выбор
 * остаётся в сессии, форма шлёт его заново на каждой загрузке страницы, и без отметки
 * диалог всплывал бы при каждой перезагрузке (F-41). Ломается отметка тихо, в обе стороны:
 *   - не поставилась или не пережила запрос — диалог повторяется на каждой загрузке;
 *   - не сбросилась вместе с эхо — следующая потеря того же выбора пройдёт молча, потому что
 *     «уже предупреждали»;
 *   - сброс пишет в сессию, когда стирать нечего — каждый анонимный посетитель получает
 *     PHP-сессию и `Set-Cookie: PHPSESSID` (P5).
 *
 * Хранилище подменяется заглушкой, которая считает записи: P5 проверяется по числу записей,
 * а не по итоговому состоянию. Webasyst не поднимается.
 *
 * Мутации, каждая обязана дать провал:
 *   - убрать ранний выход из forgetLostChoiceNotified() (сброс при пустой отметке пишет в сессию);
 *   - убрать forgetLostChoiceNotified() из clearDeliveryEcho() или clearPaymentEcho();
 *   - сбросить обе отметки разом вместо одной (потерять соседнюю);
 *   - в getPaymentMethodIds() не приводить ключи к строке (id 22 станет int);
 *   - в getPaymentMethodIds() вернуть [] вместо null, когда список не посчитан.
 *
 * Запуск: php tests/LostChoiceNotifiedMarkTest.php
 */

class waException extends Exception
{
}

/** Хранилище сессии с журналом записей: P5 — это про число обращений, а не про итог. */
class waSessionStorage
{
    public array $data = [];

    /** @var string[] Журнал вида "set:ключ" / "remove:ключ" */
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

if (!class_exists('shopPrefillPluginLog')) {
    class shopPrefillPluginLog
    {
        public static function debug($message, $context = []): void {}
        public static function info($message, $context = []): void {}
        public static function warning($message, $context = []): void {}
        public static function error($message, $context = []): void {}
    }
}

require_once dirname(__DIR__) . '/lib/classes/checkout/shopPrefillPluginLostChoiceDetector.class.php';
require_once dirname(__DIR__) . '/lib/classes/checkout/shopPrefillCheckoutState.class.php';
require_once dirname(__DIR__) . '/lib/classes/sessionstorage/shopPrefillPluginSessionStorageProvider.class.php';

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

/** Провайдер без конструктора: ему нужно только хранилище, остальные зависимости не трогаются. */
function makeProvider(waSessionStorage $storage): shopPrefillPluginSessionStorageProvider
{
    $provider = (new ReflectionClass('shopPrefillPluginSessionStorageProvider'))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty($provider, 'storage');
    $property->setAccessible(true);
    $property->setValue($provider, $storage);

    return $provider;
}

const DELIVERY = shopPrefillPluginLostChoiceDetector::KIND_DELIVERY;
const PAYMENT  = shopPrefillPluginLostChoiceDetector::KIND_PAYMENT;

// --- 1. Поставили — прочитали ---------------------------------------------------------------

$storage  = new waSessionStorage();
$provider = makeProvider($storage);

check(null, $provider->getLostChoiceNotified(DELIVERY), 'чистая сессия: предупреждений не было');

$provider->markLostChoiceNotified(DELIVERY, '33.c');
check('33.c', $provider->getLostChoiceNotified(DELIVERY), 'отметка о доставке читается тем же id');
check(null, $provider->getLostChoiceNotified(PAYMENT), 'отметка о доставке не видна как отметка об оплате');

$provider->markLostChoiceNotified(PAYMENT, '22');
check('22', $provider->getLostChoiceNotified(PAYMENT), 'отметка об оплате читается');
check('33.c', $provider->getLostChoiceNotified(DELIVERY), 'запись оплаты не затёрла доставку');

// --- 2. Мусор в сессии не считается отметкой -------------------------------------------------

$storage  = new waSessionStorage();
$provider = makeProvider($storage);
$storage->data['shop/prefill_lost_notified'] = 'не массив';
check(null, $provider->getLostChoiceNotified(DELIVERY), 'не массив в сессии → отметки нет, без ошибки');

$storage->data['shop/prefill_lost_notified'] = [DELIVERY => '', PAYMENT => ['x']];
check(null, $provider->getLostChoiceNotified(DELIVERY), 'пустая строка → отметки нет');
check(null, $provider->getLostChoiceNotified(PAYMENT), 'не строка → отметки нет');

// --- 3. Сброс снимает только свой вид -------------------------------------------------------

$storage  = new waSessionStorage();
$provider = makeProvider($storage);
$provider->markLostChoiceNotified(DELIVERY, '33.c');
$provider->markLostChoiceNotified(PAYMENT, '22');

$provider->forgetLostChoiceNotified(DELIVERY);
check(null, $provider->getLostChoiceNotified(DELIVERY), 'forget(delivery) снял отметку доставки');
check('22', $provider->getLostChoiceNotified(PAYMENT), 'forget(delivery) не тронул отметку оплаты');

$provider->forgetLostChoiceNotified(PAYMENT);
check(false, array_key_exists('shop/prefill_lost_notified', $storage->data), 'снята последняя отметка — ключ сессии удалён целиком');

// --- 4. Сброс вместе с эхо: новый выбор — новый цикл -----------------------------------------

$storage  = new waSessionStorage();
$provider = makeProvider($storage);
$provider->markLostChoiceNotified(DELIVERY, '33.c');
$provider->markLostChoiceNotified(PAYMENT, '22');

$provider->clearDeliveryEcho();
check(null, $provider->getLostChoiceNotified(DELIVERY), 'clearDeliveryEcho() снимает отметку доставки');
check('22', $provider->getLostChoiceNotified(PAYMENT), 'clearDeliveryEcho() не снимает отметку оплаты');

$provider->clearPaymentEcho();
check(null, $provider->getLostChoiceNotified(PAYMENT), 'clearPaymentEcho() снимает отметку оплаты');

// --- 5. P5: сброс пустой отметки ничего не пишет в сессию ------------------------------------

$storage  = new waSessionStorage();
$provider = makeProvider($storage);

$provider->forgetLostChoiceNotified(DELIVERY);
$provider->forgetLostChoiceNotified(PAYMENT);
check([], $storage->writes, 'forget() на чистой сессии — ни одной записи (иначе Set-Cookie: PHPSESSID у каждого гостя)');

// Тот же щит у сброса вместе с эхо: заказ оформляется и без единой потери выбора
$provider->clearDeliveryEcho();
$provider->clearPaymentEcho();
check(
    ['remove:shop/prefill_delivery_echo', 'remove:shop/prefill_payment_echo'],
    $storage->writes,
    'clear*Echo() на чистой сессии стирает только эхо и не трогает ключ отметки'
);

// Отметка о другом виде: forget() своего вида — тоже без записи
$storage  = new waSessionStorage();
$provider = makeProvider($storage);
$provider->markLostChoiceNotified(PAYMENT, '22');
$storage->writes = [];
$provider->forgetLostChoiceNotified(DELIVERY);
check([], $storage->writes, 'forget(delivery) при отметке только об оплате — без записи');

// --- 6. getPaymentMethodIds() ----------------------------------------------------------------

/** Конструктор состояния берёт параметры по ссылке — литерал передать нельзя. */
function paymentMethodIds(array $params): ?array
{
    return (new shopPrefillCheckoutState($params))->getPaymentMethodIds();
}

check(null, paymentMethodIds([]), 'нет vars.payment → null (шаг не считался), а не []');
check(null, paymentMethodIds(['vars' => ['payment' => []]]), 'нет methods → null');
check(null, paymentMethodIds(['vars' => ['payment' => ['methods' => 'x']]]), 'methods не массив → null');
check([], paymentMethodIds(['vars' => ['payment' => ['methods' => []]]]), 'пустой список посчитан → [] (подходящих способов нет — это потеря, а не неопределённость)');

// Ядро индексирует способы числовыми id: PHP отдаёт такие ключи int, а эхо хранит строку
$ids = paymentMethodIds(['vars' => ['payment' => ['methods' => [22 => ['name' => 'ЮKassa'], 23 => []]]]]);
check(['22', '23'], $ids, 'числовые ключи приведены к строкам');
check(true, in_array('22', $ids, true), 'строгое сравнение с id из эха находит способ');
check(false, in_array('24', $ids, true), 'отсутствующего способа в списке нет');

echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
