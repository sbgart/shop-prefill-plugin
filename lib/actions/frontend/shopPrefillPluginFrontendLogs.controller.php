<?php

class shopPrefillPluginFrontendLogsController extends shopPrefillPluginFrontendDebugBaseController
{
    protected function handle()
    {
        $message = (string) waRequest::post('message', '', waRequest::TYPE_STRING_TRIM);
        $message = str_replace(["\r", "\n"], ' ', $message);
        $type = waRequest::post('type', 'log');

        switch ($type) {
            case 'error':
                shopPrefillPluginLog::error("[Frontend] {$message}");
                break;
            case 'warn':
            case 'warning':
                shopPrefillPluginLog::warning("[Frontend] {$message}");
                break;
            case 'info':
                shopPrefillPluginLog::info("[Frontend] {$message}");
                break;
            case 'debug':
            case 'log':
            default:
                shopPrefillPluginLog::debug("[Frontend] {$message}");
                break;
        }
    }
}