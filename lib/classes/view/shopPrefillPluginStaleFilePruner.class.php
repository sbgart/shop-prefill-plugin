<?php

/**
 * Удаляет устаревшие файлы из каталога сгенерированных ассетов (CSS-переменные, JS-инициализатор).
 *
 * AssetsManager пишет новый файл на каждый новый хеш параметров и никогда не переиспользует
 * старые — они годами копятся в wa-data (issue-57 №3). Чистим по возрасту (TTL), а не «оставляем
 * последние N» и не пытаемся вычислить набор хешей, реально нужных прямо сейчас: у JS-файла
 * хеш зависит ещё и от isAuth/локали, не только от настроек витрины, так что «набор актуальных»
 * посчитать корректно можно только перебором всех витрин × auth-состояний — сложность того не стоит.
 * TTL проще и достаточен: у ссылки на файл в чужом закэшированном HTML есть разумный запас на дожитие.
 *
 * Не зависит от Webasyst-рантайма (только filesystem) — можно тестировать без bootstrap'а.
 */
class shopPrefillPluginStaleFilePruner
{
    /**
     * @param string $dir              Каталог с файлами, оканчивающийся на '/'
     * @param string $except_filename  Файл, который не удалять (только что записанный)
     * @param int    $ttl_seconds      Возраст, после которого файл считается устаревшим
     */
    public function prune(string $dir, string $except_filename, int $ttl_seconds): void
    {
        $paths = glob(rtrim($dir, '/') . '/*');
        if ($paths === false) {
            return;
        }

        $threshold = time() - $ttl_seconds;

        foreach ($paths as $path) {
            if (basename($path) === $except_filename || !is_file($path)) {
                continue;
            }

            $mtime = filemtime($path);
            if ($mtime !== false && $mtime < $threshold) {
                unlink($path);
            }
        }
    }
}
