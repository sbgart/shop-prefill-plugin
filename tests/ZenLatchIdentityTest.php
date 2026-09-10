<?php

/**
 * Проверяет, что защёлка дзен-режима принадлежит личности, а не браузеру —
 * docs/bugs/zen-collapse-latch-outlives-php-session.md,
 * docs/plans/zen-collapse-latch-identity-scope.md, Z4.
 *
 * Куки `prefill_zen_{group}` сессионные и переживают и PHP-сессию, и вход в аккаунт, а
 * ставятся при любом развороте — включая серверный промах «заполнять ещё нечего». Репро
 * 08.09.2026: гость открыл `/order/`, все три группы законно развернулись и записали защёлку;
 * после входа под admin те же куки коротко замкнули `shouldCollapseGroup()` — дзен-режим
 * оказался полностью нейтрализован, ни одной строки `Zen group '...' expanded` в логе.
 *
 * Четыре места, на которых легко ошибиться, и каждое закрыто отдельной проверкой:
 *   - сверка владельца обязана делаться ровно один раз за запрос: блоки трёх групп рендерятся
 *     в разных хуках, а setCookie() попутно правит $_COOKIE, поэтому повторная сверка увидела бы
 *     уже обновлённого владельца и стёрла бы защёлку, законно поставленную в этом же запросе;
 *   - чужие защёлки снимаются, а не игнорируются, — иначе возврат к прежней личности их воскресит,
 *     а группа, чей блок в этом запросе не отрисован, унесёт чужую в следующий;
 *   - гость без куки токена — это владелец null, полноценный, а не «владельца нет»;
 *   - отпечаток не вычислился — защёлки нет, но и куки не переписываются.
 *
 * Провайдер источника подменяется наследником настоящего класса (а не самостоятельной
 * заглушкой): так тест ломается, если getSourceKey() сменит имя или сигнатуру.
 * Webasyst не поднимается — waRequest/waResponse застаблены здесь же, вместе с $_COOKIE,
 * который настоящий waResponse::setCookie() правит на лету (именно на этом держится
 * «один раз за запрос»).
 *
 * Запуск: php tests/ZenLatchIdentityTest.php
 */

// --- Заглушки окружения -----------------------------------------------------

/** Читает куки ровно так же, как настоящий: из $_COOKIE. */
class waRequest
{
    /** Тестовый стенд — не HTTPS; тесту важно лишь то, что метод вызывается без ошибок. */
    public static function isHttps(): bool
    {
        return false;
    }

    /** @return mixed */
    public function cookie(string $name, $default = null)
    {
        return $_COOKIE[$name] ?? $default;
    }
}

/**
 * Пишет куки и, как настоящий waResponse, тут же правит $_COOKIE — без этого тест не увидел бы
 * главную ловушку повторной сверки.
 */
class waResponse
{
    /** @var array<int, array{name: string, value: string, expires: int}> */
    public array $sent = [];

    public function setCookie(string $name, string $value, array $options = []): self
    {
        $this->sent[] = [
            'name'    => $name,
            'value'   => $value,
            'expires' => (int) ($options['expires'] ?? 0),
        ];

        $_COOKIE[$name] = $value;

        return $this;
    }

    /** Имена кук, которые были удалены (отрицательное время жизни). */
    public function deleted(): array
    {
        $names = [];
        foreach ($this->sent as $cookie) {
            if ($cookie['expires'] < 0) {
                $names[] = $cookie['name'];
            }
        }
        return $names;
    }

    /** Значение последней записи куки или null, если её не писали. */
    public function lastValueOf(string $name): ?string
    {
        $value = null;
        foreach ($this->sent as $cookie) {
            if ($cookie['name'] === $name) {
                $value = $cookie['value'];
            }
        }
        return $value;
    }
}

class shopPrefillPluginLog
{
    /** @var string[] */
    public static array $warnings = [];

    /** @var string[] */
    public static array $debug = [];

    public static function warning($message, $context = null): void
    {
        self::$warnings[] = (string) $message;
    }

    public static function debug($message, $context = null): void
    {
        self::$debug[] = (string) $message;
    }

    public static function info($message, $context = null): void
    {
    }
}

function _wp(string $string): string
{
    return $string;
}

require_once dirname(__DIR__) . '/lib/classes/fillparams/shopPrefillPluginFillParamsProvider.class.php';
require_once dirname(__DIR__) . '/lib/classes/zenmode/shopPrefillPluginZenLatch.class.php';

/**
 * Настоящий провайдер с подменённым отпечатком: наследование держит тест на реальной
 * сигнатуре getSourceKey(), а пустой конструктор избавляет от его зависимостей.
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

/**
 * Новый запрос той же браузерной сессии: куки остаются, инстанс защёлки создаётся заново
 * (в бою он живёт ровно запрос).
 */
function latchFor(?string $source_key, bool $should_throw = false): shopPrefillPluginZenLatch
{
    global $response;
    $response = new waResponse();

    // Лог и отправленные куки наблюдаются в пределах одного запроса
    shopPrefillPluginLog::$warnings = [];
    shopPrefillPluginLog::$debug    = [];

    return new shopPrefillPluginZenLatch(
        new waRequest(),
        $response,
        new FakeSourceKeyProvider($source_key, $should_throw)
    );
}

/** Браузер закрыт: сессионных кук нет вовсе. */
function freshBrowser(): void
{
    $_COOKIE = [];
}

/** @var waResponse $response */
$response = new waResponse();

// --- 1. Своя защёлка действует ----------------------------------------------

freshBrowser();
$latch = latchFor(null); // гость без куки токена
$latch->sync('customer', false);

$latch = latchFor(null); // следующий запрос того же гостя
check(true, $latch->isExpanded('customer'), 'своя защёлка действует в следующем запросе');
check(
    false,
    $latch->isExpanded('delivery'),
    'защёлка одной группы не распространяется на другие'
);

// --- 2. Защёлка гостя не переживает вход в аккаунт ---------------------------

freshBrowser();
$latch = latchFor(null);
foreach (['customer', 'delivery', 'payment'] as $group) {
    $latch->sync($group, false); // все три развернулись «нечего сворачивать» (Z2)
}

$latch = latchFor('user:1'); // вход под admin, куки те же
check(
    false,
    $latch->isExpanded('customer'),
    'защёлка, записанная гостем, не действует после входа в аккаунт'
);
check(
    ['prefill_zen_customer', 'prefill_zen_delivery', 'prefill_zen_payment'],
    $response->deleted(),
    'чужие защёлки снимаются все три, включая те, чей блок в этом запросе не читали'
);
check(
    ['Zen latches dropped: identity changed'],
    shopPrefillPluginLog::$debug,
    'смена личности видна в логе — по ней прогон в браузере отличает сверку от совпадения'
);

// --- 3. Сверка ровно один раз за запрос --------------------------------------

// setCookie() правит $_COOKIE, поэтому вторая сверка внутри того же запроса увидела бы
// «владелец совпал» и приняла бы за чужую свежую защёлку, поставленную этим же рендером.
check(
    false,
    $latch->isExpanded('delivery'),
    'вторая группа того же запроса тоже не видит чужой защёлки',
);
$latch->sync('delivery', false); // группа развернулась уже под новой личностью
check(
    true,
    $latch->isExpanded('delivery'),
    'защёлка, поставленная в этом же запросе, повторной сверкой не стирается'
);

// --- 4. Свежий владелец записан и переживает запрос ---------------------------

$owner = $response->lastValueOf('prefill_zen_owner');
check(true, $owner !== null, 'кука-владелец записана при смене личности');
check(1, preg_match('/^[0-9a-f]{12}$/', (string) $owner), 'владелец — короткий хеш');
check(
    0,
    substr_count((string) $owner, 'user:'),
    'сырой ключ личности в куку не попадает — она видна клиенту'
);

$latch = latchFor('user:1'); // следующий запрос той же личности
check(true, $latch->isExpanded('delivery'), 'своя защёлка действует и в следующем запросе');
check([], $response->deleted(), 'совпал владелец — ничего не чистится');
check([], shopPrefillPluginLog::$debug, 'совпадение владельца лога не засоряет');

// --- 5. Обратное направление: логаут ------------------------------------------

$latch = latchFor(null); // логаут, тот же браузер
check(
    false,
    $latch->isExpanded('delivery'),
    'защёлка авторизованного не действует после логаута'
);

// --- 6. Гость без куки токена — полноценный владелец --------------------------

freshBrowser();
$latch = latchFor(null);
$latch->sync('customer', false);
$owner_anonymous = $response->lastValueOf('prefill_zen_owner');
check(true, $owner_anonymous !== null, 'у гостя без куки токена владелец тоже записан');

$latch = latchFor('guest:abc123'); // гость получил куку токена (первый заказ)
check(
    false,
    $latch->isExpanded('customer'),
    'гость с токеном и гость без токена — разные владельцы'
);

// --- 7. Защёлки без куки-владельца (браузеры с версии до фикса) ----------------

freshBrowser();
$_COOKIE['prefill_zen_customer'] = 'expanded'; // висит с прошлой версии плагина
$latch = latchFor('user:1');
check(
    false,
    $latch->isExpanded('customer'),
    'защёлка без владельца не действует — разово группа свернётся, это штатное состояние zen'
);
check(
    ['prefill_zen_customer', 'prefill_zen_delivery', 'prefill_zen_payment'],
    $response->deleted(),
    'защёлки старого формата снимаются'
);

// --- 8. Отпечаток не вычислился ------------------------------------------------

freshBrowser();
$latch = latchFor(null);
$latch->sync('customer', false);

$latch = latchFor(null, true); // провайдер сломался
check(
    false,
    $latch->isExpanded('customer'),
    'отпечаток недоступен — защёлки нет (деградация в «zen без защёлки»)'
);
check([], $response->deleted(), 'при недоступном отпечатке куки не переписываются');
check(
    ['Failed resolving zen latch owner'],
    shopPrefillPluginLog::$warnings,
    'сбой вычисления владельца попадает в лог'
);

$latch = latchFor(null); // провайдер починился, личность та же
check(
    true,
    $latch->isExpanded('customer'),
    'после сбоя своя защёлка возвращается в строй — сбой её не сжёг'
);

$latch = latchFor('user:1'); // а вот личность сменилась
check(
    false,
    $latch->isExpanded('customer'),
    'сбой не законсервировал владельца: смена личности после него ловится'
);

// --- 9. Сворачивание снимает защёлку -------------------------------------------

freshBrowser();
$latch = latchFor('user:1');
$latch->sync('customer', false);
$latch->sync('customer', true); // покупатель нажал «Свернуть»

$latch = latchFor('user:1');
check(false, $latch->isExpanded('customer'), 'сворачивание снимает защёлку');

// --- 10. Сброс после заказа -----------------------------------------------------

freshBrowser();
$latch = latchFor('user:1');
foreach (['customer', 'delivery', 'payment'] as $group) {
    $latch->sync($group, false);
}
$response->sent = [];
$latch->clearAll(); // resetState() в хуке создания заказа

check(
    ['prefill_zen_customer', 'prefill_zen_delivery', 'prefill_zen_payment'],
    $response->deleted(),
    'после заказа снимаются защёлки всех трёх групп'
);
check(
    null,
    $response->lastValueOf('prefill_zen_owner'),
    'куку-владельца заказ не трогает — личность не менялась'
);

// --- Итог -----------------------------------------------------------------------

echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
