<?php

class shopPrefillPluginLog
{
    public const LOG_FILE = 'prefill.plugin.log';

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