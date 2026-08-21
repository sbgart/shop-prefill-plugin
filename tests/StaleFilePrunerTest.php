<?php

require_once dirname(__DIR__) . '/lib/classes/view/shopPrefillPluginStaleFilePruner.class.php';

/**
 * issue-57 №3: сгенерированные CSS/JS-файлы никогда не удалялись и копились в wa-data годами.
 * Pruner чистит по возрасту (TTL) при каждом появлении нового файла — эти тесты проверяют
 * границы TTL и то, что он не трогает ничего, кроме своих файлов.
 *
 * @param mixed  $expected
 * @param mixed  $actual
 * @param string $message
 */
function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

/**
 * Готовит временный каталог для теста. Возвращает путь с завершающим '/'.
 */
function makeTempDir(): string
{
    $dir = sys_get_temp_dir() . '/prefill_pruner_test_' . uniqid('', true) . '/';
    mkdir($dir);
    return $dir;
}

function removeDir(string $dir): void
{
    foreach (glob($dir . '*') ?: [] as $path) {
        is_dir($path) ? removeDir($path . '/') : unlink($path);
    }
    rmdir($dir);
}

/**
 * @param string $path  Полный путь к файлу
 * @param int    $age   Возраст файла в секундах (0 = только что создан)
 */
function makeFileAged(string $path, int $age): void
{
    file_put_contents($path, 'x');
    touch($path, time() - $age);
}

const DAY = 24 * 60 * 60;
const TTL = 30 * DAY;

$pruner = new shopPrefillPluginStaleFilePruner();

// ---------------------------------------------------------------------------
// 1. Файлы старше TTL удаляются, свежие остаются
// ---------------------------------------------------------------------------

$dir = makeTempDir();
makeFileAged($dir . 'old.css', TTL + DAY);
makeFileAged($dir . 'fresh.css', DAY);

$pruner->prune($dir, 'kept.css', TTL);

assertSameValue(false, file_exists($dir . 'old.css'), 'файл старше TTL должен быть удалён');
assertSameValue(true, file_exists($dir . 'fresh.css'), 'файл младше TTL не должен трогаться');

removeDir($dir);

// ---------------------------------------------------------------------------
// 2. Граница TTL: возраст ровно TTL ещё не считается устаревшим (mtime не строго меньше threshold)
// ---------------------------------------------------------------------------

$dir = makeTempDir();
makeFileAged($dir . 'boundary.css', TTL);

$pruner->prune($dir, 'kept.css', TTL);

assertSameValue(true, file_exists($dir . 'boundary.css'), 'возраст ровно TTL — ещё не удаляем (safe margin)');

removeDir($dir);

// ---------------------------------------------------------------------------
// 3. except_filename не удаляется, даже если он старше TTL — это тот файл, который
//    вызывающий код только что записал (или собирается использовать как текущий)
// ---------------------------------------------------------------------------

$dir = makeTempDir();
makeFileAged($dir . 'current.css', TTL + DAY);

$pruner->prune($dir, 'current.css', TTL);

assertSameValue(true, file_exists($dir . 'current.css'), 'except_filename защищён от удаления независимо от возраста');

removeDir($dir);

// ---------------------------------------------------------------------------
// 4. Директории внутри не трогаем (is_file guard) — на случай стороннего вложенного каталога
// ---------------------------------------------------------------------------

$dir = makeTempDir();
mkdir($dir . 'subdir');
touch($dir . 'subdir', time() - TTL - DAY);

$pruner->prune($dir, 'kept.css', TTL);

assertSameValue(true, is_dir($dir . 'subdir'), 'вложенные директории pruner не трогает');

removeDir($dir);

// ---------------------------------------------------------------------------
// 5. Пустой каталог / нет мусора — без ошибок
// ---------------------------------------------------------------------------

$dir = makeTempDir();
$pruner->prune($dir, 'kept.css', TTL);
assertSameValue([], glob($dir . '*'), 'пустой каталог остаётся пустым, ошибок нет');
removeDir($dir);

// ---------------------------------------------------------------------------
// 6. Несуществующий каталог — glob() вернёт [], метод не должен падать
// ---------------------------------------------------------------------------

$pruner->prune(sys_get_temp_dir() . '/prefill_pruner_does_not_exist_' . uniqid('', true) . '/', 'kept.css', TTL);

echo "StaleFilePrunerTest: OK\n";
