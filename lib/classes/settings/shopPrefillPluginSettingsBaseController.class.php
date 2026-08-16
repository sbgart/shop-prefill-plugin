<?php

/**
 * Базовый класс backend AJAX-контроллеров настроек плагина.
 *
 * Механизм `rights` из plugin.php здесь не задействован (он проверяет доступ
 * ко всему плагину разом, а не отдельным экшенам), поэтому каждый settings-
 * контроллер обязан пройти через эту проверку — см. issue-54.
 */
abstract class shopPrefillPluginSettingsBaseController extends waJsonController
{
    final public function execute()
    {
        if (!wa()->getUser()->isAdmin(shopPrefillPlugin::APP_ID)) {
            $this->errors = 'Forbidden';
            return;
        }

        $this->handle();
    }

    abstract protected function handle();
}
