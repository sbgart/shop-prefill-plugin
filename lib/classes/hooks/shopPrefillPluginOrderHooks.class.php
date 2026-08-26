<?php

/**
 * Обработчик хуков связанных с заказами
 */
class shopPrefillPluginOrderHooks
{
    private shopPrefillPluginSessionStorageProvider $session_storage;
    private shopPrefillPluginOrderProvider $order_provider;
    private shopPrefillPluginGuestTokenStorage $guest_token_storage;
    private shopPrefillPluginZenMode $zen_mode;
    private shopPrefillPluginUserProvider $user_provider;
    private shopPrefillPluginConsentStorage $consent_storage;
    private shopPrefillPluginGeoSync $geo_sync;
    private array $storefront_settings;

    public function __construct(
        shopPrefillPluginSessionStorageProvider $session_storage,
        shopPrefillPluginOrderProvider $order_provider,
        shopPrefillPluginGuestTokenStorage $guest_token_storage,
        shopPrefillPluginZenMode $zen_mode,
        shopPrefillPluginUserProvider $user_provider,
        shopPrefillPluginConsentStorage $consent_storage,
        shopPrefillPluginGeoSync $geo_sync,
        array $storefront_settings
    ) {
        $this->session_storage = $session_storage;
        $this->order_provider = $order_provider;
        $this->guest_token_storage = $guest_token_storage;
        $this->zen_mode = $zen_mode;
        $this->user_provider = $user_provider;
        $this->consent_storage = $consent_storage;
        $this->geo_sync = $geo_sync;
        $this->storefront_settings = $storefront_settings;
    }

    /**
     * Хук срабатывает при создании заказа.
     * Сохраняем дополнительные параметры заказа и хеш гостя для предзаполнения.
     *
     * @param array $data Данные заказа
     * @throws waException
     */
    public function handleOrderActionCreate(array $data): void
    {
        if (!isset($data['order_id'])) {
            return;
        }

        // Витрина неактивна — не сохраняем prefill-данные заказа и хеш гостя.
        if (!$this->storefront_settings['active']) {
            return;
        }

        $order_id = (int) $data['order_id'];

        // Для неавторизованных: выдаём токен и привязываем к нему заказ
        $this->saveGuestLink($order_id);

        // Источник изменился — следующий цикл предзаполнения обязан перечитать его
        $this->session_storage->clearSourceMarker();

        // Город фактического заказа — теперь он и есть наш слепок. Без этого покупатель,
        // сменивший город в ходе оформления, навсегда расходится с нашей записью, правило
        // G1 уводит интеграцию в отступление, и она для него тихо умирает
        $this->rememberOrderCity();

        // Помечаем заказ, который авторизует покупателя без его выбора
        $this->markPendingAuth();

        // Сбрасываем состояние Zen Mode: cookies групп и кэш данных сводки
        $this->zen_mode->resetState();

        // Заказ создан — эхо-кэши не должны пережить его и достаться следующему заказу
        $this->session_storage->clearPaymentEcho();
        $this->session_storage->clearDeliveryEcho();

        shopPrefillPluginLog::info('Order creation hook processed successfully', [
            'order_id' => $order_id
        ]);
    }

    /**
     * Передаёт городу заказа статус «наша запись» в состоянии гео-синхронизации.
     *
     * Адрес берём из сессии чекаута, а не из заказа: хук срабатывает внутри $order->save(),
     * сессия ещё цела, и лишнего запроса к базе не требуется.
     */
    private function rememberOrderCity(): void
    {
        try {
            $region = $this->session_storage->getCheckoutParams()['order']['region'] ?? null;

            if (! is_array($region)) {
                return;
            }

            $this->geo_sync->rememberOrderCity(shopPrefillPluginGeoTarget::fromArray($region));
        } catch (Throwable $e) {
            shopPrefillPluginLog::warning('Failed remembering order city for geo sync', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ставит метку, если этот заказ авторизует покупателя, а выбора у него не было.
     *
     * Хук order_action.create срабатывает внутри $order->save(), то есть ДО
     * shopConfirmationChannel::postConfirm(), где и происходит авторизация — здесь
     * покупатель ещё гость. Сама метка потребляется на следующей загрузке страницы:
     * постфактум по cookie этот случай неотличим от явного отказа от «Запомнить меня».
     */
    private function markPendingAuth(): void
    {
        if (empty($this->storefront_settings['prefill']['remember_me']['on_order'])) {
            return;
        }

        // Уже авторизован — его согласием (галочкой) ведает продление на frontend_head
        if ($this->user_provider->isAuth()) {
            return;
        }

        if ($this->getOrderWithoutAuthMode() === 'create_contact') {
            // Разовый контакт: postConfirm() не авторизует покупателя вовсе
            return;
        }

        $this->session_storage->setPendingAuth();
    }

    /**
     * Режим обновления профилей покупателей из настроек чекаута магазина.
     *
     * @return string 'create_contact' | 'existing_contact' | 'confirm_contact' | '' при ошибке чтения
     */
    private function getOrderWithoutAuthMode(): string
    {
        if (!class_exists('shopCheckoutConfig')) {
            return '';
        }

        try {
            $config = new shopCheckoutConfig(true);
        } catch (Exception $e) {
            shopPrefillPluginLog::warning('Failed reading checkout config for pending auth', [
                'message' => $e->getMessage()
            ]);
            return '';
        }

        $mode = $config['confirmation']['order_without_auth'] ?? '';

        return is_string($mode) ? $mode : '';
    }

    /**
     * Связывает заказ с гостем.
     *
     * Единственная точка, где выдаётся гостевая кука: до первого завершённого заказа
     * посетитель идентификатора не получает, и его отсутствие означает «истории нет».
     * Логика согласия не меняется: не требуется ИЛИ получено.
     *
     * @param int $order_id ID заказа
     * @throws waException
     * @throws waDbException
     */
    private function saveGuestLink(int $order_id): void
    {
        if ($this->user_provider->isAuth()) {
            return;
        }

        if (!$this->storefront_settings['prefill']['guest']['enabled']) {
            shopPrefillPluginLog::debug('Skipping saveGuestLink: guest prefill is disabled');
            return;
        }

        $consent_required = $this->storefront_settings['prefill']['guest']['consent_required'];
        $has_consent = $this->consent_storage->hasConsent();

        if (!$consent_required || $has_consent) {
            $token = $this->guest_token_storage->getOrCreateToken();
            $this->guest_token_storage->linkOrder($order_id, $token);
        }
    }

}
