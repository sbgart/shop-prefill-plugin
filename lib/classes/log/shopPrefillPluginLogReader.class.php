<?php

class shopPrefillPluginLogReader
{
    /** Максимум читаемых байт с конца файла */
    private const MAX_BYTES = 1048576; // 1 MB

    /**
     * Читает оба лог-файла, объединяет и сортирует по времени.
     * Лимит по числу записей не применяется — естественная граница задана MAX_BYTES (1MB) на файл.
     * Срезать записи нельзя: error-лог обычно содержит более старые записи,
     * чем main-лог (warning/error реже debug/info), и при срезе они вырезаются первыми.
     */
    public static function readMerged(): array
    {
        $main  = self::read(shopPrefillPluginLog::LOG_FILE,       PHP_INT_MAX);
        $error = self::read(shopPrefillPluginLog::ERROR_LOG_FILE, PHP_INT_MAX);

        $all = array_merge($main, $error);

        usort($all, static fn(array $a, array $b): int => strcmp($a['datetime'], $b['datetime']));

        return $all;
    }

    /**
     * Читает один лог-файл и возвращает распарсенные записи (последние $max_entries).
     *
     * @param string $file_key  Имя файла из констант shopPrefillPluginLog
     * @param int    $max_entries Максимальное число записей
     * @return array
     */
    public static function read(string $file_key, int $max_entries = 1000): array
    {
        $log_path = wa()->getConfig()->getPath('log') . '/' . $file_key;

        if (!file_exists($log_path)) {
            return [];
        }

        $content = self::readTail($log_path);

        if ($content === '') {
            return [];
        }

        return self::parseEntries($content, $max_entries);
    }

    private static function readTail(string $path): string
    {
        $size = filesize($path);

        if ($size === 0) {
            return '';
        }

        if ($size <= self::MAX_BYTES) {
            return file_get_contents($path);
        }

        // Читаем только последний мегабайт и отбрасываем неполную первую запись
        $fh = fopen($path, 'rb');
        fseek($fh, -self::MAX_BYTES, SEEK_END);
        $content = fread($fh, self::MAX_BYTES);
        fclose($fh);

        // Пропускаем первую, потенциально обрезанную запись
        $pos = strpos($content, "\n");
        return $pos !== false ? substr($content, $pos + 1) : $content;
    }

    private static function parseEntries(string $content, int $max): array
    {
        // Каждая запись начинается со строки вида "2026-04-14 14:17:22 127.0.0.1"
        $raw_chunks = preg_split('/(?=^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/m', $content, -1, PREG_SPLIT_NO_EMPTY);

        if (count($raw_chunks) > $max) {
            $raw_chunks = array_slice($raw_chunks, -$max);
        }

        $entries = [];
        foreach ($raw_chunks as $chunk) {
            $entry = self::parseEntry(trim($chunk));
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private static function parseEntry(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        $lines = explode("\n", $raw);

        // Строка 1: дата + IP [+ user:ID]
        $header = array_shift($lines);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(\S+)(?:\s+user:(\d+))?/', $header, $m)) {
            return null;
        }
        $datetime = $m[1];
        $ip       = $m[2];
        $user_id  = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;

        if (empty($lines)) {
            return null;
        }

        // Строка 2: [LEVEL] message
        $level_line = array_shift($lines);
        if (!preg_match('/^\[([A-Z]+)]\s*(.*)$/', $level_line, $m)) {
            return null;
        }
        $level   = strtolower($m[1]);
        $message = trim($m[2]);

        // Определяем источник и стрипаем технический префикс из текста
        $source = 'backend';
        if (strpos($message, '[Frontend]') !== false) {
            $source = 'frontend';
            $message = trim(str_replace('[Frontend]', '', $message));
        }

        // Оставшиеся строки — опциональный Context
        $context = null;
        if (!empty($lines)) {
            $context_text = trim(implode("\n", $lines));
            if (strpos($context_text, 'Context:') === 0) {
                $json_str = trim(substr($context_text, strlen('Context:')));
                $decoded  = json_decode($json_str, true);
                $context  = json_last_error() === JSON_ERROR_NONE ? $decoded : $json_str;
            }
        }

        return [
            'datetime' => $datetime,
            'ip'       => $ip,
            'user_id'  => $user_id,
            'level'    => $level,
            'source'   => $source,
            'message'  => $message,
            'context'  => $context,
        ];
    }
}
