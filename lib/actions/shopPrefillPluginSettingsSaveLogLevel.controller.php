<?php

/**
 * AJAX: сохраняет уровень логирования в глобальных настройках плагина.
 * Доступен по ?module=prefillPluginSettingsSaveLogLevel
 */
class shopPrefillPluginSettingsSaveLogLevelController extends shopPrefillPluginSettingsBaseController
{
    protected function handle(): void
    {
        $level = waRequest::post('level', 'warning');
        $valid = ['off', 'error', 'warning', 'info', 'debug'];

        if (!in_array($level, $valid, true)) {
            $this->errors = 'Invalid level';
            return;
        }

        $plugin   = shopPrefillPlugin::getInstance();
        $provider = $plugin->getSettingProvider();
        $provider->setSetting('logging', ['level' => $level]);

        shopPrefillPluginLog::setLevel($level);

        $this->response = ['saved' => true, 'level' => $level];
    }
}
