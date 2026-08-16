<?php

/**
 * AJAX: очищает оба лог-файла плагина вместе с их ротированными поколениями.
 * Доступен по ?module=prefillPluginSettingsClearLog
 */
class shopPrefillPluginSettingsClearLogController extends shopPrefillPluginSettingsBaseController
{
    protected function handle(): void
    {
        $log_dir = wa()->getConfig()->getPath('log') . '/';

        foreach ([shopPrefillPluginLog::LOG_FILE, shopPrefillPluginLog::ERROR_LOG_FILE] as $file) {
            $path = $log_dir . $file;
            if (file_exists($path)) {
                file_put_contents($path, '');
            }

            // Ротированное поколение иначе всплывёт в просмотрщике сразу после очистки
            $rotated = $path . shopPrefillPluginLog::ROTATED_SUFFIX;
            if (file_exists($rotated)) {
                @unlink($rotated);
            }
        }

        $this->response = ['cleared' => true];
    }
}
