<?php

/**
 * A2 (TEST-PLAN.md §5), правило P6: формат гостевого токена и производного lookup id.
 *
 *   кука:   prefill_guest_token = 64 hex  (bin2hex(random_bytes(32)))
 *   в БД:   name = 'prefill_guest_' . <48 hex производного id>
 *
 * getLookupId() обязан быть детерминирован (иначе повторный визит не находит свой же
 * заказ) и не равен самому токену (сырой токен не должен утекать в параметры заказа).
 * Мусорная кука любого вида обязана читаться как отсутствие токена — молча, без ошибки:
 * подделка должна вести к индексному промаху по name, а не к исключению на витрине.
 *
 * user_provider и order_params_model в проверяемых методах не используются — стабы пустые.
 * waRequest подменён управляемым значением куки, waResponse — только фиксирует setCookie().
 *
 * Запуск: php tests/GuestTokenFormatTest.php
 */

// --- Заглушки окружения -----------------------------------------------------

class shopPrefillPluginLog
{
    public static function debug(string $message, array $context = []): void
    {
    }

    public static function info(string $message, array $context = []): void
    {
    }

    public static function warning(string $message, array $context = []): void
    {
    }
}

/** Пустой стаб — методы не вызываются ни одним из проверяемых путей. */
class shopPrefillPluginUserProvider
{
}

/** Пустой стаб по той же причине. */
class shopOrderParamsModel
{
}

class waRequest
{
    const TYPE_STRING = 'string';

    /** @var mixed */
    public static $cookie_value = null;

    /** @return mixed */
    public static function cookie(string $name, $default = null, $type = null)
    {
        return self::$cookie_value ?? $default;
    }

    public static function isHttps(): bool
    {
        return false;
    }
}

class waResponse
{
    /** @var array<int, array{0: string, 1: string, 2: array}> */
    public array $cookies_set = [];

    public function setCookie(string $name, string $value, array $options = []): void
    {
        $this->cookies_set[] = [$name, $value, $options];
    }
}

require_once dirname(__DIR__) . '/lib/classes/fillparams/shopPrefillPluginGuestTokenStorage.class.php';

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

function newStorage(): shopPrefillPluginGuestTokenStorage
{
    return new shopPrefillPluginGuestTokenStorage(
        new shopPrefillPluginUserProvider(),
        new shopOrderParamsModel(),
        new waResponse()
    );
}

const TOKEN_RE     = '/\A[a-f0-9]{64}\z/';
const LOOKUP_ID_RE = '/\A[a-f0-9]{48}\z/';

// ---------------------------------------------------------------------------
// 1. getToken(): валидный формат принимается как есть
// ---------------------------------------------------------------------------

$valid_token         = str_repeat('a1', 32); // 64 hex-символа
waRequest::$cookie_value = $valid_token;

check($valid_token, newStorage()->getToken(), 'валидная 64-символьная hex-кука возвращается как есть');

// ---------------------------------------------------------------------------
// 2. Мусорная кука любого вида — отсутствие токена
// ---------------------------------------------------------------------------

$garbage_cases = [
    'пусто (null)'                  => null,
    'пустая строка'                 => '',
    'короткий мусор'                => 'abc',
    '65 символов (перебор длины)'   => str_repeat('a', 65),
    '63 символа (недобор длины)'    => str_repeat('a', 63),
    'не-hex символы той же длины'   => str_repeat('g', 64),
    'верхний регистр'               => strtoupper(str_repeat('a1', 32)),
];

foreach ($garbage_cases as $label => $value) {
    waRequest::$cookie_value = $value;
    check(null, newStorage()->getToken(), "мусорная кука считается отсутствующей: {$label}");
}

// ---------------------------------------------------------------------------
// 3. getOrCreateToken(): формат созданного токена и идемпотентность при готовой куке
// ---------------------------------------------------------------------------

waRequest::$cookie_value = null;
$storage                 = newStorage();
$created                 = $storage->getOrCreateToken();

check(1, preg_match(TOKEN_RE, $created), 'getOrCreateToken() без куки создаёт 64-символьный hex-токен');
check(1, count($storage->getResponse()->cookies_set), 'новый токен записывается в куку ровно один раз');

waRequest::$cookie_value = $valid_token;
check($valid_token, newStorage()->getOrCreateToken(), 'при уже существующей валидной куке новый токен не создаётся');

// ---------------------------------------------------------------------------
// 4. getLookupId(): формат, детерминизм, отличие от самого токена
// ---------------------------------------------------------------------------

$storage    = newStorage();
$token_a    = str_repeat('a1', 32);
$token_b    = str_repeat('b2', 32);
$lookup_a1  = $storage->getLookupId($token_a);
$lookup_a2  = $storage->getLookupId($token_a);
$lookup_b   = $storage->getLookupId($token_b);

check(1, preg_match(LOOKUP_ID_RE, $lookup_a1), 'lookup id — 48-символьная hex-строка');
check($lookup_a1, $lookup_a2, 'getLookupId() детерминирован: тот же токен — тот же lookup id');
check(true, $lookup_a1 !== $token_a, 'lookup id не равен самому токену');
check(true, $lookup_a1 !== $lookup_b, 'разные токены дают разные lookup id');

// ---------------------------------------------------------------------------
// 5. getParamName(): префикс + lookup id, укладывается в varchar(64)
// ---------------------------------------------------------------------------

$param_name = $storage->getParamName($token_a);

check('prefill_guest_' . $lookup_a1, $param_name, 'getParamName() — это фиксированный префикс плюс lookup id');
check(true, strlen($param_name) <= 64, 'getParamName() укладывается в shop_order_params.name varchar(64)');

waRequest::$cookie_value = $token_a;
check($param_name, newStorage()->getParamName(), 'getParamName() без аргумента берёт токен из текущей куки');

waRequest::$cookie_value = null;
check(null, newStorage()->getParamName(), 'без валидной куки getParamName() — null, к БД обращаться незачем');

// ---------------------------------------------------------------------------
echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
