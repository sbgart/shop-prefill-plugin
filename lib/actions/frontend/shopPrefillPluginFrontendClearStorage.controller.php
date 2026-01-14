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
        // Устанавливаем правильный заголовок
        if (! headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        try {
            // Очищаем хранилище checkout (сессия)
            wa()->getStorage()->remove('shop/checkout');

            $this->response = [
                'status'  => 'ok',
                'message' => 'Checkout session cleared. Order data preserved in database.'
            ];
        } catch (Exception $e) {
            $this->errors = [
                'error' => $e->getMessage()
            ];
        }
    }
}
