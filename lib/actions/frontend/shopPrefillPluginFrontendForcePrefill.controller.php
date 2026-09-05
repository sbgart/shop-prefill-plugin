<?php

class shopPrefillPluginFrontendForcePrefillController extends waJsonController
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

            // Получаем параметры для заполнения
            $fill_params = $plugin->getFillParamsProvider()->getFillParams();

            // Выполняем предзаполнение
            $plugin->getSessionStorageProvider()->preFillCheckoutParams($fill_params);

            $this->response = [
                'status' => 'ok',
                'message' => 'Checkout params prefilled successfully',
                'params' => $fill_params->toArray()
            ];
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Failed force prefilling checkout params in shopPrefillPluginFrontendForcePrefillController', [
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
