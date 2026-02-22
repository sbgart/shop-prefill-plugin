<?php

class shopPrefillPluginLog
{
    public const LOG_FILE = 'prefill.plugin.log';
    public const ERROR_LOG_FILE = 'prefill.plugin.error.log';

    public static function debug($message, $context = null): void
    {
        if (!waSystemConfig::isDebug()) {
            return;
        }
        self::writeLog($message, $context, self::LOG_FILE, 'DEBUG');
    }

    public static function info($message, $context = null): void
    {
        if (!waSystemConfig::isDebug()) {
            return;
        }
        self::writeLog($message, $context, self::LOG_FILE, 'INFO');
    }

    public static function warning($message, $context = null): void
    {
        self::writeLog($message, $context, self::ERROR_LOG_FILE, 'WARNING');
    }

    public static function error($message, $context = null): void
    {
        self::writeLog($message, $context, self::ERROR_LOG_FILE, 'ERROR');
    }

    private static function writeLog($message, $context, $file, $level): void
    {
        $log_message = "[{$level}] {$message}";
        if ($context !== null) {
            if (is_array($context) || is_object($context)) {
                $log_message .= "\nContext: " . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } else {
                $log_message .= "\nContext: " . (string) $context;
            }
        }

        waLog::log($log_message, $file);
    }

    // Поддержка старого метода (deprecated, но оставим для обратной совместимости на случай, если где-то используется напрямую)
    public static function log($error, $file = null): void
    {
        if (!waSystemConfig::isDebug()) {
            return;
        }

        if ($file === null) {
            $file = self::LOG_FILE;
        }

        if (is_string($error)) {
            waLog::log($error, $file);
        } else {
            waLog::dump($error, $file);
        }
    }

    public static function details($error, $file = null)
    {
        if (waRequest::cookie('prefill_plugin_details')) {
            self::log($error, $file);
        }
    }
}