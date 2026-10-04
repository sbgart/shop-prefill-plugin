<?php

/**
 * Кука-просьба `expand-request` на стороне сервера (ZenLatch): клик «Изменить» пишет не
 * состояние, а просьбу, и серверу нужно отличать её от защёлки «группа развёрнута».
 *
 * Ломается тихо, в обе стороны:
 *   - просьба не считается защёлкой — разворот по клику не сработает вообще: сервер свернёт
 *     группу обратно в том же запросе;
 *   - защёлка считается просьбой — любая развёрнутая группа на каждом рендере будет «просить»
 *     развернуться, и отказ по блокировке (shouldCollapseGroup) сработает там, где группа
 *     открыта давно и трогать её нельзя;
 *   - просьба переживает рендер своей группы — разворот сработает с опозданием, на действии,
 *     которого покупатель уже не ждёт (F-38);
 *   - просьба переживает смену личности — чужая просьба развернёт группу новому покупателю.
 *
 * Решение «что делать с просьбой при блокировке» держит ZenCollapseDecisionTest; здесь —
 * только куки: как просьба читается и когда гаснет. Webasyst не поднимается.
 *
 * Мутации, каждая обязана дать провал:
 *   - убрать EXPAND_REQUEST из isExpanded();
 *   - в isExpandRequested() сравнивать с EXPANDED;
 *   - в remember() считать защёлкой и просьбу (гард перестанет переписывать её в 'expanded');
 *   - убрать вызов clearAll() из reconcile().
 *
 * Запуск: php tests/ZenLatchExpandRequestTest.php
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

const GROUP = 'delivery';
const COOKIE = 'prefill_zen_delivery';

/** Браузер с одной кукой группы и уже записанным владельцем (не первый кадр). */
function browserWith(?string $zen_cookie, ?string $source_key = 'user:1'): shopPrefillPluginZenLatch
{
    freshBrowser();
    // Владелец записывается первым рендером под этой личностью; куки группы — после него
    latchFor($source_key)->sync('customer', true);
    if ($zen_cookie !== null) {
        $_COOKIE[COOKIE] = $zen_cookie;
    }

    return latchFor($source_key);
}

// --- 1. Как читается кука ---------------------------------------------------------------

$latch = browserWith('expand-request');
check(true, $latch->isExpandRequested(GROUP), 'просьба распознаётся как просьба');
check(true, $latch->isExpanded(GROUP), 'просьба считается развёрнутым состоянием (иначе разворот не сработает)');

$latch = browserWith('expanded');
check(false, $latch->isExpandRequested(GROUP), 'защёлка не считается просьбой');
check(true, $latch->isExpanded(GROUP), 'защёлка по-прежнему держит группу развёрнутой');

$latch = browserWith(null);
check(false, $latch->isExpandRequested(GROUP), 'куки нет → просьбы нет');
check(false, $latch->isExpanded(GROUP), 'куки нет → не развёрнута');

foreach (['yes', '1', 'EXPAND-REQUEST', 'expand-request '] as $garbage) {
    $latch = browserWith($garbage);
    check(false, $latch->isExpandRequested(GROUP), "мусор '{$garbage}' не считается просьбой");
    check(false, $latch->isExpanded(GROUP), "мусор '{$garbage}' не держит группу развёрнутой");
}

$latch = browserWith('expand-request');
check(false, $latch->isExpandRequested('customer'), 'просьба одной группы не распространяется на другие');

// --- 2. Просьба гаснет на рендере своей группы ------------------------------------------

// Развернули: просьба становится защёлкой
$latch = browserWith('expand-request');
$latch->sync(GROUP, false);
check('expanded', $response->lastValueOf(COOKIE), 'развернули → просьба переписана в защёлку');
check(false, in_array(COOKIE, $response->deleted(), true), 'развернули → кука не удалена');

$latch = latchFor('user:1'); // следующий запрос
check(false, $latch->isExpandRequested(GROUP), 'после разворота просьбы в следующем запросе нет');
check(true, $latch->isExpanded(GROUP), 'после разворота группа держится защёлкой');

// Отказали (группа осталась свёрнутой): просьба снята вместе с защёлкой
$latch = browserWith('expand-request');
$latch->sync(GROUP, true);
check(true, in_array(COOKIE, $response->deleted(), true), 'отказали → кука снята');

$latch = latchFor('user:1');
check(false, $latch->isExpandRequested(GROUP), 'после отказа просьбы в следующем запросе нет');
check(false, $latch->isExpanded(GROUP), 'после отказа группа не развёрнута');

// Гард remember(): уже стоящая защёлка не переписывается каждый рендер (issue-100 §1)
$latch = browserWith('expanded');
$latch->sync(GROUP, false);
check(null, $response->lastValueOf(COOKIE), 'защёлка уже стоит → лишний Set-Cookie не шлём');

// --- 3. Чужая просьба не переживает смену личности ---------------------------------------

$latch = browserWith('expand-request', 'user:1');
$latch = latchFor('user:2'); // в браузере вошёл другой покупатель, просьба висела от прежнего
check(false, $latch->isExpandRequested(GROUP), 'просьба прежней личности не действует на новую');
check(false, $latch->isExpanded(GROUP), 'и не держит группу развёрнутой');
check(true, in_array(COOKIE, $response->deleted(), true), 'чужая просьба снята вместе с остальными защёлками');

// --- 4. Отпечаток не вычислился ----------------------------------------------------------

freshBrowser();
$_COOKIE[COOKIE] = 'expand-request';
$latch = latchFor(null, true);
check(false, $latch->isExpandRequested(GROUP), 'отпечатка нет → просьба не действует (B2a)');
check(false, $latch->isExpanded(GROUP), 'отпечатка нет → защёлок нет');
check([], $response->sent, 'отпечатка нет → куки не переписываются');

echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
