<?php

/**
 * AJAX: очищает оба лог-файла плагина.
 * Доступен по ?module=prefillPluginSettingsClearLog
 */
class shopPrefillPluginSettingsClearLogController extends waJsonController
{
    public function execute(): void
    {
        if (!wa()->getUser()->isAdmin('shop')) {
            $this->errors = 'Forbidden';
            return;
        }

        $log_dir = wa()->getConfig()->getPath('log') . '/';

        foreach ([shopPrefillPluginLog::LOG_FILE, shopPrefillPluginLog::ERROR_LOG_FILE] as $file) {
            $path = $log_dir . $file;
            if (file_exists($path)) {
                file_put_contents($path, '');
            }
        }

        $this->response = ['cleared' => true];
    }
}
