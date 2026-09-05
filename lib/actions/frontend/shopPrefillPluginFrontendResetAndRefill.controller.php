<?php

/**
 * Контроллер для полной очистки формы и повторного предзаполнения
 * Используется для debug через кнопку "Reset & Refill"
 */
class shopPrefillPluginFrontendResetAndRefillController extends waJsonController
{
    public function execute()
    {
        if (!$this->isAllowed()) {
            $this->errors = 'Access denied';
            return;
        }

        try {
            $plugin = shopPrefillPlugin::getInstance();

            // Явная команда: маркер и кэш сбрасываем, источник читаем заново
            $plugin->getSessionStorageProvider()->clearSourceMarker();
            shopPrefillPluginFillParamsProvider::clearMemo();

            // Получаем параметры для предзаполнения
            $fill_params = $plugin->getFillParamsProvider()->getFillParams();

            // Очищаем и перезаполняем
            $plugin->getSessionStorageProvider()->resetAndRefill($fill_params);

            $this->response = [
                'status' => 'ok',
                'message' => 'Form cleared and refilled successfully'
            ];
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Failed resetting and refilling form in shopPrefillPluginFrontendResetAndRefillController', [
                'message' => $e->getMessage()
            ]);
            $this->errors = [
                'error' => $e->getMessage()
            ];
        }
    }

    private function isAllowed(): bool
    {
        $sent = (string) waRequest::post('_csrf', '', waRequest::TYPE_STRING_TRIM);
        $cookie = (string) waRequest::cookie('_csrf', '', waRequest::TYPE_STRING_TRIM);
        return waRequest::method() === 'post'
            && shopPrefillPlugin::getInstance()->isDebug()
            && wa()->getUser()->isAdmin('shop')
            && $sent !== ''
            && hash_equals($cookie, $sent);
    }
}
