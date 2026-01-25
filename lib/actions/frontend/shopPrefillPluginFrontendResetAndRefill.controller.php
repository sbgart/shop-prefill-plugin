<?php

/**
 * Контроллер для полной очистки формы и повторного предзаполнения
 * Используется для debug через кнопку "Reset & Refill"
 */
class shopPrefillPluginFrontendResetAndRefillController extends waJsonController
{
    public function execute()
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        try {
            $plugin = shopPrefillPlugin::getInstance();

            // Получаем параметры для предзаполнения
            $fill_params = $plugin->getFillParamsProvider()->getFillParams();

            // Очищаем и перезаполняем
            $plugin->getSessionStorageProvider()->resetAndRefill($fill_params);

            $this->response = [
                'status' => 'ok',
                'message' => 'Form cleared and refilled successfully'
            ];
        } catch (Exception $e) {
            $this->errors = [
                'error' => $e->getMessage()
            ];
        }
    }
}
