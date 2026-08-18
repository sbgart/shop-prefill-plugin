<?php

class shopPrefillPluginFrontendForcePrefillController extends waJsonController
{
    public function execute()
    {
        if (! shopPrefillPlugin::getInstance()->isDebug() || ! wa()->getUser()->isAdmin('shop')) {
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
}
