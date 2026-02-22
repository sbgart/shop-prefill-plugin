<?php

class shopPrefillPluginFrontendLogsController extends waJsonController
{
    public function execute()
    {
        $message = waRequest::post('message', null);
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