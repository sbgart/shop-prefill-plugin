<?php

/**
 * Контроллер управления согласием и историей предзаполнения
 *
 * Действия:
 * - grant       — дать согласие на сохранение данных
 * - revoke      — отозвать согласие, удалить связи в заказах и токен (чтобы следующий за ПК не видел старые данные)
 * - clear       — очистить историю (удалить связи в заказах и токен)
 * - clear_form  — очистить сессию формы оформления заказа (checkout)
 */
class shopPrefillPluginFrontendConsentController extends waJsonController
{
    /** Допустимые действия. Всё остальное отсекается до того, как попадёт в лог */
    private const ACTIONS = ['grant', 'revoke', 'clear', 'clear_form'];

    /**
     * @throws waException
     */
    public function execute()
    {
        $action = waRequest::post('action', 'grant', waRequest::TYPE_STRING);

        if (!in_array($action, self::ACTIONS, true)) {
            // Само значение в лог не пишем: эндпоинт публичный, строку задаёт клиент.
            // Уровень debug (по умолчанию выключен) — иначе любой посетитель наполняет лог в цикле
            shopPrefillPluginLog::debug('Unknown action received in consent controller');
            $this->errors[] = _wp('error.unknown_action');
            return;
        }

        $plugin = shopPrefillPlugin::getInstance();

        try {
            switch ($action) {
                case 'grant':
                    $plugin->getConsentStorage()->grantConsent();
                    shopPrefillPluginLog::info('User granted prefill consent');
                    $this->response = ['status' => 'ok', 'message' => _wp('message.consent.granted')];
                    break;

                case 'revoke':
                    $plugin->getConsentStorage()->revokeConsent();
                    // Порядок важен: связи удаляются по имени, выведенному из токена,
                    // поэтому куку стираем только после них — иначе строки станут
                    // недостижимыми сиротами навсегда.
                    $plugin->getGuestTokenStorage()->forget();
                    $plugin->getSessionStorageProvider()->clearSourceMarker();
                    // Подставленный нами город стороннему плагину — тоже данные прошлого
                    // покупателя: без этого он останется в шапке следующему за компьютером
                    $plugin->getGeoSync()->forgetEverything();
                    shopPrefillPluginLog::info('User revoked prefill consent');
                    $this->response = ['status' => 'ok', 'message' => _wp('message.consent.revoked')];
                    break;

                case 'clear_form':
                    wa()->getStorage()->remove('shop/checkout');
                    $plugin->getSessionStorageProvider()->clearSourceMarker();
                    $plugin->getSessionStorageProvider()->clearPaymentEcho();
                    $plugin->getSessionStorageProvider()->clearDeliveryEcho();
                    shopPrefillPluginLog::info('User cleared checkout form session');
                    $this->response = ['status' => 'ok', 'message' => _wp('message.form_data_cleared')];
                    break;

                case 'clear':
                    $plugin->getGuestTokenStorage()->forget();
                    $plugin->getSessionStorageProvider()->clearSourceMarker();
                    $plugin->getGeoSync()->forgetEverything();
                    shopPrefillPluginLog::info('User cleared guest prefill history');
                    $this->response = ['status' => 'ok', 'message' => _wp('message.history_cleared')];
                    break;
            }
        } catch (Exception $e) {
            // $action здесь заведомо из белого списка — писать его в лог безопасно
            shopPrefillPluginLog::error('Error processing consent action', [
                'action' => $action,
                'message' => $e->getMessage()
            ]);
            $this->errors[] = _wp('error.internal');
        }
    }
}
