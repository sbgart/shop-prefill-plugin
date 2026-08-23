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
    private array $storefront_settings;
    private waRequest $request;

    public function __construct(
        shopPrefillPluginSessionStorageProvider $session_storage,
        shopPrefillPluginOrderProvider $order_provider,
        shopPrefillPluginGuestTokenStorage $guest_token_storage,
        shopPrefillPluginZenMode $zen_mode,
        shopPrefillPluginUserProvider $user_provider,
        shopPrefillPluginConsentStorage $consent_storage,
        array $storefront_settings,
        waRequest $request
    ) {
        $this->session_storage = $session_storage;
        $this->order_provider = $order_provider;
        $this->guest_token_storage = $guest_token_storage;
        $this->zen_mode = $zen_mode;
        $this->user_provider = $user_provider;
        $this->consent_storage = $consent_storage;
        $this->storefront_settings = $storefront_settings;
        $this->request = $request;
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

        $checkout_params = $this->session_storage->getCheckoutParams();

        // Сохраняем shipping_type_id (для предзаполнения следующего заказа)
        $this->saveShippingType($order_id, $checkout_params);

        // Для неавторизованных: выдаём токен и привязываем к нему заказ
        $this->saveGuestLink($order_id);

        // Источник изменился — следующий цикл предзаполнения обязан перечитать его
        $this->session_storage->clearSourceMarker();

        // Помечаем заказ, который авторизует покупателя без его выбора
        $this->markPendingAuth();

        // Сбрасываем состояние Zen Mode: cookies групп и кэш данных сводки
        $this->zen_mode->resetState();

        // Заказ создан — эхо-кэш payment не должен пережить его и достаться следующему
        $this->session_storage->clearPaymentEcho();

        shopPrefillPluginLog::info('Order creation hook processed successfully', [
            'order_id' => $order_id
        ]);
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
     * Сохраняет тип доставки в параметры заказа
     *
     * @param int $order_id ID заказа
     * @param array $checkout_params Параметры checkout
     */
    private function saveShippingType(int $order_id, array $checkout_params): void
    {
        $shipping_type_id = $checkout_params['order']['shipping']['type_id'] ?? '';

        if (!$shipping_type_id) {
            $shipping_post = $this->request->post('shipping', [], waRequest::TYPE_ARRAY_TRIM);
            if (!empty($shipping_post['type_id'])) {
                $shipping_type_id = $shipping_post['type_id'];
            }
        }

        $this->order_provider->storeShippingTypeId($order_id, (string) $shipping_type_id);
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
