<?php

/**
 * Контроллер для сброса checkout сессии (ранее сбрасывал флаг first_prefill_done)
 * Используется для дебага через кнопку в debug окне
 */
class shopPrefillPluginFrontendResetFirstPrefillDoneController extends waJsonController
{
    public function execute()
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        try {
            /** @var shopPrefillPlugin $plugin */
            $plugin = wa('shop')->getPlugin('prefill');
            $session_storage = $plugin->getSessionStorageProvider();

            // Флага first_prefill_done больше нет — секции контролируются через isSectionFilled().
            // Для сброса достаточно очистить checkout сессию целиком.
            $session_storage->getStorage()->remove('shop/checkout');
            $session_storage->prefilled = false;

            $this->response = [
                'status' => 'ok',
                'message' => 'Checkout session cleared. Prefill will run on next request.'
            ];
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Failed resetting checkout session in shopPrefillPluginFrontendResetFirstPrefillDoneController', [
                'message' => $e->getMessage()
            ]);
            $this->errors = [
                'error' => $e->getMessage()
            ];
        }
    }
}
