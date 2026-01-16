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

        switch ($action) {
            case 'grant':
                $plugin->getConsentStorage()->grantConsent();
                $this->response = ['status' => 'ok', 'message' => _wp('Согласие получено')];
                break;

            case 'revoke':
                $plugin->getConsentStorage()->revokeConsent();
                $this->response = ['status' => 'ok', 'message' => _wp('Согласие отозвано')];
                break;

            case 'clear':
                $plugin->getGuestHashStorage()->clearGuestHash();
                $this->response = ['status' => 'ok', 'message' => _wp('История очищена')];
                break;

            default:
                $this->errors[] = _wp('Неизвестное действие');
        }
    }
}
