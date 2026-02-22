<?php

/**
 * Контроллер управления согласием и историей предзаполнения
 *
 * Действия:
 * - grant  — дать согласие на сохранение данных
 * - revoke — отозвать согласие
 * - clear  — очистить историю (удалить guest_hash)
 */
class shopPrefillPluginFrontendConsentController extends waJsonController
{
    /**
     * @throws waException
     */
    public function execute()
    {
        $action = waRequest::post('action', 'grant', waRequest::TYPE_STRING);

        $plugin = shopPrefillPlugin::getInstance();

        try {
            switch ($action) {
                case 'grant':
                    $plugin->getConsentStorage()->grantConsent();
                    shopPrefillPluginLog::info('User granted prefill consent');
                    $this->response = ['status' => 'ok', 'message' => _wp('Согласие получено')];
                    break;

                case 'revoke':
                    $plugin->getConsentStorage()->revokeConsent();
                    shopPrefillPluginLog::info('User revoked prefill consent');
                    $this->response = ['status' => 'ok', 'message' => _wp('Согласие отозвано')];
                    break;

                case 'clear':
                    $plugin->getGuestHashStorage()->clearGuestHash();
                    shopPrefillPluginLog::info('User cleared guest hash history');
                    $this->response = ['status' => 'ok', 'message' => _wp('История очищена')];
                    break;

                default:
                    shopPrefillPluginLog::warning('Unknown action received in consent controller', ['action' => $action]);
                    $this->errors[] = _wp('Неизвестное действие');
            }
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Error processing consent action', [
                'action' => $action,
                'message' => $e->getMessage()
            ]);
            $this->errors[] = _wp('Внутренняя ошибка');
        }
    }
}
