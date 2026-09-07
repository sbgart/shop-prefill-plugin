<?php

/**
 * Проверяет, что кэш сводки дзен-режима принадлежит личности, а не сессии —
 * docs/bugs/zen-summary-cache-leaks-across-identity-change.md,
 * docs/plans/zen-summary-cache-identity-scope.md, R4.
 *
 * Сессия переживает смену личности целиком: PHPSESSID при логауте и логине не меняется,
 * поэтому без штампа владельца следующая личность видела в свёрнутой карточке данные
 * предыдущей. Репро 07.09.2026: гость свернул «Доставку» с курьером СДЭК в Москву, после
 * логина под admin та же карточка показала его выбор (`Zen summary for 'delivery' rendered
 * from cache` под `user:1`).
 *
 * Три случая, на которых легко ошибиться, и каждый закрыт отдельной проверкой:
 *   - гость без куки — это владелец null, а не «владельца нет» (кука выдаётся только при
 *     первом оформленном заказе), поэтому сверять надо array_key_exists, а не `?? null`;
 *   - записи старого формата (плоский массив групп без штампа) обязаны читаться как промах,
 *     а не падать;
 *   - запись новой личности обязана вытеснять чужую, иначе данные ушедшего гостя копятся
 *     в сессии.
 *
 * Провайдер источника подменяется наследником настоящего класса (а не самостоятельной
 * заглушкой): так тест ломается, если getSourceKey() сменит имя или сигнатуру.
 * Webasyst не поднимается — waSessionStorage/waException/_wp() застаблены здесь же.
 *
 * Запуск: php tests/ZenSummaryCacheIdentityTest.php
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
    /** @var string[] */
    public static array $warnings = [];

    public static function warning(string $message, array $context = []): void
    {
        self::$warnings[] = $message;
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

/**
 * Настоящий провайдер с подменённым отпечатком: наследование держит тест на реальной
 * сигнатуре getSourceKey(), а пустой конструктор избавляет от пяти его зависимостей.
 */
class FakeSourceKeyProvider extends shopPrefillPluginFillParamsProvider
{
    private ?string $source_key;
    private bool $should_throw;

    public function __construct(?string $source_key, bool $should_throw = false)
    {
        $this->source_key   = $source_key;
        $this->should_throw = $should_throw;
    }

    public function getSourceKey(): ?string
    {
        if ($this->should_throw) {
            throw new RuntimeException('source key is unavailable');
        }

        return $this->source_key;
    }
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
    } else {
        echo "OK: {$message}\n";
    }
}

/** Кэш поверх общего хранилища — так моделируется одна и та же сессия у разных личностей. */
function cacheFor(waSessionStorage $storage, ?string $source_key): shopPrefillPluginZenSummaryCache
{
    return new shopPrefillPluginZenSummaryCache($storage, new FakeSourceKeyProvider($source_key));
}

const DELIVERY_DATA = ['shipping_name' => 'СДЭК (Курьер, Москва)'];

// --- 1. Своя запись читается ------------------------------------------------

$storage = new waSessionStorage();
cacheFor($storage, 'user:1')->set('delivery', DELIVERY_DATA);

check(
    DELIVERY_DATA,
    cacheFor($storage, 'user:1')->get('delivery'),
    'своя запись читается: владелец совпал'
);

// --- 2. Чужая запись не читается (тот самый баг) ----------------------------

check(
    [],
    cacheFor($storage, 'user:2')->get('delivery'),
    'чужая запись не читается: другой контакт в той же сессии'
);

// --- 3. Гость без куки (владелец null) и авторизованный — разные личности ----

$storage = new waSessionStorage();
cacheFor($storage, null)->set('delivery', DELIVERY_DATA);

check(
    [],
    cacheFor($storage, 'user:1')->get('delivery'),
    'данные гостя без куки не достаются авторизованному (репро 07.09.2026)'
);

check(
    DELIVERY_DATA,
    cacheFor($storage, null)->get('delivery'),
    'гость без куки читает собственную запись: null — полноценный владелец, а не «нет владельца»'
);

$storage = new waSessionStorage();
cacheFor($storage, 'user:1')->set('delivery', DELIVERY_DATA);

check(
    [],
    cacheFor($storage, null)->get('delivery'),
    'обратное направление: данные авторизованного не достаются гостю после логаута'
);

// --- 4. Гостевая кука отличает одного гостя от другого ----------------------

$storage = new waSessionStorage();
cacheFor($storage, 'guest:aaa')->set('delivery', DELIVERY_DATA);

check(
    [],
    cacheFor($storage, 'guest:bbb')->get('delivery'),
    'разные гостевые токены — разные личности'
);

// --- 5. Старый формат записи (до штампа) читается как промах ----------------

$storage = new waSessionStorage();
$storage->set('shop/prefill_zen_summary', ['delivery' => DELIVERY_DATA]);

check(
    [],
    cacheFor($storage, 'user:1')->get('delivery'),
    'запись старого формата без штампа не читается и не роняет рендер'
);

// --- 6. Запись новой личности вытесняет чужую, а не копится рядом -----------

$storage = new waSessionStorage();
cacheFor($storage, 'user:1')->set('delivery', DELIVERY_DATA);
cacheFor($storage, 'user:2')->set('delivery', ['shipping_name' => 'Почта России']);

$stored = $storage->get('shop/prefill_zen_summary');

check(
    'user:2',
    $stored['owner'],
    'после записи новой личности владелец записи — она сама'
);

check(
    ['delivery' => ['shipping_name' => 'Почта России']],
    $stored['groups'],
    'данные прежней личности вытеснены, а не сложены рядом'
);

// --- 7. Сбой вычисления владельца — промах, а не чужие данные (B2a) ---------

$storage = new waSessionStorage();
cacheFor($storage, 'user:1')->set('delivery', DELIVERY_DATA);

$broken = new shopPrefillPluginZenSummaryCache($storage, new FakeSourceKeyProvider(null, true));
shopPrefillPluginLog::$warnings = [];

check(
    [],
    $broken->get('delivery'),
    'не удалось вычислить владельца — промах, а не подстановка непонятно чьего'
);

check(
    1,
    count(shopPrefillPluginLog::$warnings),
    'сбой вычисления владельца попадает в лог'
);

// --- 8. clear() убирает запись целиком --------------------------------------

$storage = new waSessionStorage();
$cache   = cacheFor($storage, 'user:1');
$cache->set('delivery', DELIVERY_DATA);
$cache->clear();

check(
    [],
    $cache->get('delivery'),
    'clear() убирает запись (сброс при создании заказа продолжает работать)'
);

check(
    null,
    $storage->get('shop/prefill_zen_summary'),
    'clear() не оставляет за собой пустой оболочки в сессии'
);

// --- Итог -------------------------------------------------------------------

echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
