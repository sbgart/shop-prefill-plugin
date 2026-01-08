<?php

class shopPrefillPluginFrontendLogsController extends waJsonController
{
    public function execute()
    {
        $message = waRequest::post('message', null);
        $type = waRequest::post('type', 'log');

        $file_name = shopPrefillPlugin::PLUGIN_ID . "." . shopPrefillPlugin::APP_ID;

        if ($type !== 'log') {
            $file_name .= ".{$type}";
        }

        waLog::log($message, "{$file_name}.log");
    }
}