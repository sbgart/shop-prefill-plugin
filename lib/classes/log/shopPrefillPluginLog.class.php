<?php

class shopPrefillPluginLog
{
    public const LOG_FILE = 'prefill.plugin.log';
    public const ERROR_LOG_FILE = 'prefill.plugin.error.log';

    private static ?string $configured_level = null;

    /**
     * Инициализируется из shopPrefillPlugin::getSettingProvider() после загрузки настроек.
     * Не зависит от плагина — уровень передаётся снаружи.
     */
    public static function setLevel(string $level): void
    {
        $valid = ['off', 'error', 'warning', 'info', 'debug'];
        self::$configured_level = in_array($level, $valid, true) ? $level : 'warning';
    }

    public static function debug($message, $context = null): void
    {
        if (!self::isLevelEnabled('debug')) {
            return;
        }
        self::writeLog($message, $context, self::LOG_FILE, 'DEBUG');
    }

    public static function info($message, $context = null): void
    {
        if (!self::isLevelEnabled('info')) {
            return;
        }
        self::writeLog($message, $context, self::LOG_FILE, 'INFO');
    }

    public static function warning($message, $context = null): void
    {
        if (!self::isLevelEnabled('warning')) {
            return;
        }
        self::writeLog($message, $context, self::ERROR_LOG_FILE, 'WARNING');
    }

    public static function error($message, $context = null): void
    {
        if (!self::isLevelEnabled('error')) {
            return;
        }
        self::writeLog($message, $context, self::ERROR_LOG_FILE, 'ERROR');
    }

    private static function getLevel(): string
    {
        // Дефолт 'warning' до момента инициализации из настроек плагина
        return self::$configured_level ?? 'warning';
    }

    private static function isLevelEnabled(string $level): bool
    {
        $order = ['off' => 0, 'error' => 1, 'warning' => 2, 'info' => 3, 'debug' => 4];
        $configured_num = $order[self::getLevel()] ?? 2;
        $level_num = $order[$level] ?? 4;
        return $level_num <= $configured_num;
    }

    private static function writeLog($message, $context, $file, $level): void
    {
        $log_path = wa()->getConfig()->getPath('log') . '/' . $file;

        // IP: X-Forwarded-For имеет приоритет (как в waLog)
        $ip = !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
            : ($_SERVER['REMOTE_ADDR'] ?? 'cli');

        $header = date('Y-m-d H:i:s') . ' ' . $ip;

        // Добавляем ID авторизованного пользователя к строке-заголовку
        try {
            $user_id = wa()->getUser()->getId();
            if ($user_id > 0) {
                $header .= ' user:' . $user_id;
            }
        } catch (Exception $e) {
            // CLI или контекст без пользователя — пропускаем
        }

        $body = "[{$level}] {$message}";
        if ($context !== null) {
            if (is_array($context) || is_object($context)) {
                $body .= "\nContext: " . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } else {
                $body .= "\nContext: " . (string) $context;
            }
        }

        file_put_contents($log_path, "\n{$header}\n{$body}\n", FILE_APPEND | LOCK_EX);
    }
}
