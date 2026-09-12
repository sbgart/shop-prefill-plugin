<?php

/**
 * A8 (TEST-PLAN.md §5): все ключи, реально используемые через _wp() в lib/ и templates/,
 * есть в обоих .po (ru_RU, en_US), и ни один msgstr не пуст.
 *
 * Два вызывающих синтаксиса в кодовой базе:
 *   - функция:   _wp('key')            — PHP (lib/) и Smarty (templates/)
 *   - модификатор: 'key'|_wp           — Smarty, см. templates/actions/settings/blocks/Head.html
 *
 * Динамические ключи (`{_wp($tab_locale_key)}` в Tabs.html) регулярным выражением не
 * ловятся и намеренно пропускаются — их содержимое не литерал, проверять тут нечего.
 * _w() (без 'p') сюда не входит: это чужой домен локали (ядро), см. TODO.md про
 * `_w('day off')` — намеренное исключение, не пропущенный _wp().
 *
 * Запуск: php tests/LocaleCompletenessTest.php
 */

$root = dirname(__DIR__);

/**
 * @return array<string, true>
 */
function scanFiles(string $dir, string $extension): array
{
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() === $extension) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/**
 * @return array<string, true>
 */
function extractWpKeysFromPhp(string $path): array
{
    $keys = [];
    $src  = (string) file_get_contents($path);

    if (preg_match_all('/\b_wp\(\s*([\'"])((?:(?!\1).)*)\1/s', $src, $m)) {
        foreach ($m[2] as $k) {
            $keys[$k] = true;
        }
    }

    return $keys;
}

/**
 * @return array<string, true>
 */
function extractWpKeysFromTemplate(string $path): array
{
    $keys = [];
    $src  = (string) file_get_contents($path);

    // {_wp('key')} / {_wp("key")}
    if (preg_match_all('/\b_wp\(\s*([\'"])((?:(?!\1).)*)\1/s', $src, $m)) {
        foreach ($m[2] as $k) {
            $keys[$k] = true;
        }
    }

    // 'key'|_wp  /  "key"|_wp
    if (preg_match_all('/([\'"])((?:(?!\1).)*)\1\s*\|\s*_wp\b/s', $src, $m)) {
        foreach ($m[2] as $k) {
            $keys[$k] = true;
        }
    }

    return $keys;
}

/**
 * Простой построчный .po-парсер с поддержкой многострочной конкатенации ("a" "b" -> "ab").
 * Первая запись (msgid "") — заголовок PO-файла, не перевод, исключается.
 *
 * @return array<string, string> msgid => msgstr
 */
function parsePo(string $path): array
{
    $entries = [];
    $lines   = preg_split('/\r\n|\r|\n/', (string) file_get_contents($path));

    $mode    = null; // 'id' | 'str' | null
    $cur_id  = null;
    $cur_str = '';

    $flush = static function () use (&$entries, &$cur_id, &$cur_str): void {
        if ($cur_id !== null && $cur_id !== '') {
            $entries[$cur_id] = $cur_str;
        }
        $cur_id  = null;
        $cur_str = '';
    };

    foreach ($lines as $line) {
        if (preg_match('/^msgid\s+"(.*)"\s*$/', $line, $m)) {
            $flush();
            $cur_id = stripcslashes($m[1]);
            $mode   = 'id';
            continue;
        }
        if (preg_match('/^msgstr\s+"(.*)"\s*$/', $line, $m)) {
            $cur_str = stripcslashes($m[1]);
            $mode    = 'str';
            continue;
        }
        if ($mode !== null && preg_match('/^\s*"(.*)"\s*$/', $line, $m)) {
            $piece = stripcslashes($m[1]);
            if ($mode === 'id') {
                $cur_id .= $piece;
            } else {
                $cur_str .= $piece;
            }
        }
    }
    $flush();

    return $entries;
}

// --- Инфраструктура проверок ------------------------------------------------

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
    }
}

// ---------------------------------------------------------------------------
// Сбор ключей из кода
// ---------------------------------------------------------------------------

$used_keys = [];
foreach (scanFiles($root . '/lib', 'php') as $file) {
    $used_keys += extractWpKeysFromPhp($file);
}
foreach (scanFiles($root . '/templates', 'html') as $file) {
    $used_keys += extractWpKeysFromTemplate($file);
}

check(true, count($used_keys) > 100, 'сканер нашёл разумное количество вызовов _wp() в lib/ и templates/ (щит от сломанного пути/пустой директории)');

// ---------------------------------------------------------------------------
// Парсинг .po
// ---------------------------------------------------------------------------

$ru_path = $root . '/locale/ru_RU/LC_MESSAGES/shop_prefill.po';
$en_path = $root . '/locale/en_US/LC_MESSAGES/shop_prefill.po';

check(true, file_exists($ru_path), 'ru_RU.po существует');
check(true, file_exists($en_path), 'en_US.po существует');

$ru = parsePo($ru_path);
$en = parsePo($en_path);

check(true, count($ru) > 100, 'парсер вытащил разумное количество записей из ru_RU.po');
check(true, count($en) > 100, 'парсер вытащил разумное количество записей из en_US.po');

// ---------------------------------------------------------------------------
// Каждый использованный ключ есть в обеих локалях
// ---------------------------------------------------------------------------

$missing_ru = [];
$missing_en = [];
foreach ($used_keys as $key => $_) {
    if (!array_key_exists($key, $ru)) {
        $missing_ru[] = $key;
    }
    if (!array_key_exists($key, $en)) {
        $missing_en[] = $key;
    }
}

sort($missing_ru);
sort($missing_en);

check([], $missing_ru, 'все ключи _wp() из кода присутствуют в ru_RU.po');
check([], $missing_en, 'все ключи _wp() из кода присутствуют в en_US.po');

// ---------------------------------------------------------------------------
// Ни один msgstr не пуст
// ---------------------------------------------------------------------------

$empty_ru = [];
foreach ($ru as $key => $msgstr) {
    if (trim($msgstr) === '') {
        $empty_ru[] = $key;
    }
}
sort($empty_ru);
check([], $empty_ru, 'в ru_RU.po нет ключей с пустым msgstr');

$empty_en = [];
foreach ($en as $key => $msgstr) {
    if (trim($msgstr) === '') {
        $empty_en[] = $key;
    }
}
sort($empty_en);
check([], $empty_en, 'в en_US.po нет ключей с пустым msgstr');

// ---------------------------------------------------------------------------
echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
