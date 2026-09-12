<?php

/**
 * A3 (TEST-PLAN.md §5), правило R4: поведение кэша сводки дзен-режима, не покрытое
 * ZenSummaryCacheIdentityTest.php (та проверяет только принадлежность записи личности).
 *
 * Здесь — функциональная сторона:
 *   - hasFreshData(): решает, принёс ли текущий рендер данные группы, по PRESENCE_FIELDS;
 *   - set()/get(): сохраняются и читаются только поля своей группы (GROUP_FIELDS →
 *     shopPrefillPluginZenData::getAvailableFields()), лишние ключи входа отсекаются;
 *   - clear(): убирает запись целиком.
 *
 * R4 — кэш только хранит данные и не участвует в решении «сворачивать или нет»: у класса
 * нет ни одного метода с этим именем, что и проверяется структурно ниже.
 *
 * Запуск: php tests/ZenSummaryCacheTest.php
 */

// --- Заглушки окружения -----------------------------------------------------

class waException extends Exception
{
}

/** Хранилище сессии в памяти: наружу торчат ровно те три метода, которыми пользуется кэш. */
class waSessionStorage
{
    /** @var array<string, mixed> */
    public array $data = [];

    /** @return mixed */
    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /** @param mixed $value */
    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }
}

class shopPrefillPluginLog
{
    public static function warning(string $message, array $context = []): void
    {
    }

    public static function debug(string $message, array $context = []): void
    {
    }
}

function _wp(string $string): string
{
    return $string;
}

require_once dirname(__DIR__) . '/lib/classes/fillparams/shopPrefillPluginFillParamsProvider.class.php';
require_once dirname(__DIR__) . '/lib/classes/zenmode/shopPrefillPluginZenData.class.php';
require_once dirname(__DIR__) . '/lib/classes/zenmode/shopPrefillPluginZenSummaryCache.class.php';

/** Пустой конструктор — единственный вызываемый метод ниже это getSourceKey(). */
class FakeSourceKeyProvider extends shopPrefillPluginFillParamsProvider
{
    private ?string $source_key;

    public function __construct(?string $source_key = 'user:1')
    {
        $this->source_key = $source_key;
    }

    public function getSourceKey(): ?string
    {
        return $this->source_key;
    }
}

function newCache(): shopPrefillPluginZenSummaryCache
{
    return new shopPrefillPluginZenSummaryCache(new waSessionStorage(), new FakeSourceKeyProvider());
}

// --- Инфраструктура проверок ------------------------------------------------

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
    }
}

// ---------------------------------------------------------------------------
// hasFreshData()
// ---------------------------------------------------------------------------

$cache = newCache();

check(
    true,
    $cache->hasFreshData('customer', ['firstname' => 'Иван']),
    'customer: непустой firstname — данные есть'
);
check(
    false,
    $cache->hasFreshData('customer', ['firstname' => '', 'lastname' => '', 'phone' => '', 'email' => '']),
    'customer: все presence-поля пусты — данных нет'
);
check(
    false,
    $cache->hasFreshData('customer', ['firstname' => '   ']),
    'customer: только пробелы — trim() не даёт ложное «есть данные»'
);
check(
    false,
    $cache->hasFreshData('customer', ['company' => 'ООО Ромашка']),
    'company не входит в presence-поля customer — не считается признаком заполненности'
);

check(
    true,
    $cache->hasFreshData('delivery', ['shipping_name' => 'СДЭК', 'city' => 'Москва']),
    'delivery: presence-поле — именно shipping_name (название способа), а не адрес'
);
check(
    false,
    $cache->hasFreshData('delivery', ['city' => 'Москва', 'street' => 'Ленина']),
    'delivery: адресные поля одни, без shipping_name, — данных группы нет (шаг region рендерится и при коротком замыкании)'
);

check(
    true,
    $cache->hasFreshData('payment', ['payment_name' => 'Онлайн-оплата']),
    'payment: presence-поле — payment_name'
);
check(false, $cache->hasFreshData('payment', []), 'payment: пустой набор данных — данных нет');

check(false, $cache->hasFreshData('unknown_group', ['firstname' => 'Иван']), 'неизвестная группа — всегда false, а не ошибка');

check(
    true,
    $cache->hasFreshData('delivery', ['shipping_name' => ['not', 'a', 'string']]),
    'непустой массив в presence-поле тоже считается данными (не только строка)'
);

// ---------------------------------------------------------------------------
// set() / get(): только поля своей группы, лишнее отсекается
// ---------------------------------------------------------------------------

$cache = newCache();
$cache->set('customer', [
    'firstname'      => 'Иван',
    'lastname'       => 'Иванов',
    'phone'          => '+7...',
    'email'          => 'i@example.com',
    'company'        => 'ООО Ромашка',
    'shipping_name'  => 'СДЭК', // поле чужой группы — не должно попасть в кэш customer
]);

$stored = $cache->get('customer');

check(true, isset($stored['firstname']), 'своё поле группы сохраняется');
check(true, isset($stored['company']), 'company — тоже поле группы contact, сохраняется');
check(false, isset($stored['shipping_name']), 'поле чужой группы (delivery) в кэш customer не попадает');

check([], newCache()->get('customer'), 'чтение до записи — пустой массив, не ошибка');

// set() с пустым результатом fieldsOf() (неизвестная группа) — молча ничего не делает
$cache = newCache();
$cache->set('unknown_group', ['x' => 'y']);
check([], $cache->get('unknown_group'), 'set() для неизвестной группы не создаёт запись');

// ---------------------------------------------------------------------------
// clear()
// ---------------------------------------------------------------------------

$cache = newCache();
$cache->set('payment', ['payment_name' => 'Онлайн-оплата']);
check(true, $cache->get('payment') !== [], 'предварительное условие: запись реально сохранена');

$cache->clear();
check([], $cache->get('payment'), 'clear() убирает запись целиком');

// ---------------------------------------------------------------------------
// R4: кэш не принимает решений о сворачивании — у класса нет такого метода
// ---------------------------------------------------------------------------

$methods = get_class_methods('shopPrefillPluginZenSummaryCache');
$decision_like = array_filter($methods, static function (string $m) {
    return stripos($m, 'collapse') !== false || stripos($m, 'shouldShow') !== false;
});

check([], array_values($decision_like), 'R4: кэш хранит данные, но не решает — методов collapse/shouldShow у него нет');

// ---------------------------------------------------------------------------
echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
