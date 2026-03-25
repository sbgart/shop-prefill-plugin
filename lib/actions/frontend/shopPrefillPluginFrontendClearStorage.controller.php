<?php

/**
 * Контроллер для очистки хранилища checkout
 * Используется для дебага через кнопку в debug окне
 *
 * Данные предзаполнения хранятся в shop_order_params и очищать их не нужно.
 */
class shopPrefillPluginFrontendClearStorageController extends waJsonController
{
    public function execute()
    {
        if (!waSystemConfig::isDebug() || !wa()->getUser()->isAdmin('shop')) {
            $this->errors = 'Access denied';
            return;
        }

        try {
            // Очищаем хранилище checkout (сессия)
            wa()->getStorage()->remove('shop/checkout');

            $this->response = [
                'status' => 'ok',
                'message' => 'Checkout session cleared. Order data preserved in database.'
            ];
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Failed clearing session storage in shopPrefillPluginFrontendClearStorageController', [
                'message' => $e->getMessage()
            ]);
            $this->errors = [
                'error' => $e->getMessage()
            ];
        }
    }
}
