<?php

/**
 * AJAX: сохраняет уровень логирования в глобальных настройках плагина.
 * Доступен по ?module=prefillPluginSettingsSaveLogLevel
 */
class shopPrefillPluginSettingsSaveLogLevelController extends waJsonController
{
    public function execute(): void
    {
        if (!wa()->getUser()->isAdmin('shop')) {
            $this->errors = 'Forbidden';
            return;
        }

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
