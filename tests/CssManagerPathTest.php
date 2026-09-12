<?php

/**
 * A7 (TEST-PLAN.md §5), правило A1: shopPrefillPluginCssManager — cache busting URL
 * (issue-82) и защита от path traversal в getFilePath()/sanitizeCode() (issue-98,
 * CLAUDE.md: «путь traversal в CssManager закрыт sanitizeCode(), / и \ заменяются,
 * одни точки без разделителя безопасны»).
 *
 * wa() застаблен глобальной функцией: getDataUrl()/getDataPath() возвращают
 * детерминированные значения без обращения к реальной файловой системе Webasyst.
 *
 * Запуск: php tests/CssManagerPathTest.php
 */

class shopPrefillPluginLog
{
    public static function error(string $message, array $context = []): void
    {
    }

    public static function info(string $message, array $context = []): void
    {
    }

    public static function debug(string $message, array $context = []): void
    {
    }
}

const FAKE_PUBLIC_DIR = '/fake/wa-data/public/shop/plugins/prefill/css/';

class FakeWa
{
    public function getDataUrl(string $path, bool $absolute, string $domain): string
    {
        return '/wa-data/public/' . $domain . '/plugins/prefill/css/' . basename($path);
    }

    public function getDataPath(string $path, bool $absolute, string $domain): string
    {
        return FAKE_PUBLIC_DIR;
    }
}

function wa(): FakeWa
{
    static $instance = null;
    return $instance ??= new FakeWa();
}

require_once dirname(__DIR__) . '/lib/classes/css/shopPrefillPluginCssManager.class.php';

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

$manager = new shopPrefillPluginCssManager('prefill', '/plugin/path');

// ---------------------------------------------------------------------------
// getPublicUrl(): cache busting через update_time (issue-82)
// ---------------------------------------------------------------------------

$url_no_bust = $manager->getPublicUrl('code1', 0);
check(false, strpos($url_no_bust, '?') !== false, 'update_time=0 — без query-строки вообще');

$url_v100 = $manager->getPublicUrl('code1', 100);
$url_v200 = $manager->getPublicUrl('code1', 200);

check(true, substr($url_v100, -4) === '?100', 'update_time=100 добавляет "?100" в конец URL');
check(true, substr($url_v200, -4) === '?200', 'update_time=200 добавляет "?200"');
check(true, $url_v100 !== $url_v200, 'разный update_time — разный URL (cache busting реально меняет ссылку)');

// база URL (без query) одинакова для разных update_time одного и того же кода
check(
    explode('?', $url_v100)[0],
    explode('?', $url_v200)[0],
    'меняется только query-строка, путь к файлу остаётся тем же'
);

// ведущий "/" из wa()->getDataUrl() обрезан (иначе двойной "//" ломает URL — issue-76)
check(false, strpos($url_no_bust, '//') === 0, 'getPublicUrl() не начинается с двойного слэша');

// ---------------------------------------------------------------------------
// getFilePath(): path traversal закрыт на уровне sanitizeCode()
// ---------------------------------------------------------------------------

$public_dir = rtrim(FAKE_PUBLIC_DIR, '/');

$malicious_codes = [
    '../../../etc/passwd',
    '..\\..\\windows\\system32',
    'a/../../b',
    '/etc/passwd',
    'code/with/slashes',
    "code\x00null",
];

foreach ($malicious_codes as $code) {
    $path = $manager->getFilePath($code);
    check(
        $public_dir,
        rtrim(dirname($path), '/'),
        'вредоносный код "' . addcslashes($code, "\0..\x1f") . '" не выводит путь за пределы публичной директории'
    );
    check(false, strpos(basename($path), '/') !== false, 'имя файла для "' . addcslashes($code, "\0..\x1f") . '" не содержит "/"');
}

// Одни точки без разделителя — безопасны (CLAUDE.md): остаются внутри имени файла,
// новую директорию не создают, потому что перед ними нет "/".
$dots_path = $manager->getFilePath('..');
check($public_dir, rtrim(dirname($dots_path), '/'), 'код ".." без слэша не поднимается на директорию выше — dirname тот же публичный каталог');
check('frontend_...css', basename($dots_path), 'код ".." встраивается в единое имя файла frontend_...css');

// Легитимные коды доходят до пути без искажения состава символов, где это безопасно
check('frontend_4bed657311d6ac81b8e58fdee33d1a92.css', basename($manager->getFilePath('4bed657311d6ac81b8e58fdee33d1a92')), 'реальный hex-код checkout_storefront_id не искажается');
check('frontend_wa-dev.loc.css', basename($manager->getFilePath('wa-dev.loc')), 'точки и дефис в легитимном коде допустимы (легаси base64-подобные коды)');

// getFilePath() детерминирован
check($manager->getFilePath('same-code'), $manager->getFilePath('same-code'), 'getFilePath() детерминирован для одного и того же кода');

// ---------------------------------------------------------------------------
echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
