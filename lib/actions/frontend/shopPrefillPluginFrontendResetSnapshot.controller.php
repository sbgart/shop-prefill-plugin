<?php

/**
 * Сброс хранилища снапшота (Prefill Snapshot) для debug-панели.
 */
class shopPrefillPluginFrontendResetSnapshotController extends waJsonController
{
    public function execute()
    {
        if (!waSystemConfig::isDebug() || !wa()->getUser()->isAdmin('shop')) {
            $this->errors = 'Access denied';
            return;
        }

        try {
            shopPrefillPlugin::getInstance()->getSessionStorageProvider()->clearSnapshot();
            $this->response = ['status' => 'ok'];
        } catch (Exception $e) {
            shopPrefillPluginLog::error('ResetSnapshot failed', [
                'message' => $e->getMessage(),
            ]);
            $this->errors = $e->getMessage();
        }
    }
}
