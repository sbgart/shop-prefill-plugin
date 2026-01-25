<?php

/**
 * Контроллер для сброса флага first_prefill_done
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
            
            $success = $session_storage->resetFirstPrefillDoneFlag();

            if ($success) {
                $this->response = [
                    'status' => 'ok',
                    'message' => 'First prefill done flag reset.'
                ];
            } else {
                $this->errors = [
                    'error' => 'Failed to reset flag or session is empty'
                ];
            }
        } catch (Exception $e) {
            $this->errors = [
                'error' => $e->getMessage()
            ];
        }
    }
}
