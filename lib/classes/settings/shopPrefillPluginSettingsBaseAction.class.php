<?php

/**
 * Базовый класс backend view-экшенов настроек плагина.
 *
 * Механизм `rights` из plugin.php здесь не задействован (он проверяет доступ
 * ко всему плагину разом, а не отдельным экшенам), поэтому каждый settings-
 * экшен обязан пройти через эту проверку — см. issue-54.
 */
abstract class shopPrefillPluginSettingsBaseAction extends waViewAction
{
    /**
     * @throws waRightsException
     */
    final public function execute()
    {
        if (!wa()->getUser()->isAdmin(shopPrefillPlugin::APP_ID)) {
            throw new waRightsException();
        }

        $this->handle();
    }

    abstract protected function handle();
}
