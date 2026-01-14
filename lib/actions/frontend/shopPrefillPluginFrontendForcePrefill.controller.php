<?php

class shopPrefillPluginFrontendForcePrefillController extends waJsonController
{
    public function execute()
    {
        try {
            $plugin = shopPrefillPlugin::getInstance();

            // Получаем параметры для заполнения
            $fill_params = $plugin->getFillParamsProvider()->getFillParams();

            // Выполняем предзаполнение
            $plugin->getSessionStorageProvider()->preFillCheckoutParams($fill_params);

            $this->response = [
                'status'  => 'ok',
                'message' => 'Checkout params prefilled successfully',
                'params'  => $fill_params->toArray()
            ];
        } catch (Exception $e) {
            $this->errors = [
                'error' => $e->getMessage()
            ];
        }
    }
}
