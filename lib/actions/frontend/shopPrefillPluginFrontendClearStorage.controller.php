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
        if (! shopPrefillPlugin::getInstance()->isDebug() || ! wa()->getUser()->isAdmin('shop')) {
            $this->errors = 'Access denied';
            return;
        }

        try {
            // Очищаем хранилище checkout (сессия) вместе с эхо-кэшами: иначе следующий
            // же запрос вернёт в «очищенную» форму выбор доставки и оплаты
            wa()->getStorage()->remove('shop/checkout');
            $session_storage = shopPrefillPlugin::getInstance()->getSessionStorageProvider();
            $session_storage->clearSourceMarker();
            $session_storage->clearPaymentEcho();
            $session_storage->clearDeliveryEcho();

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
