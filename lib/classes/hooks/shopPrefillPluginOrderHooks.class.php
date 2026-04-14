<?php

/**
 * Обработчик хуков связанных с заказами
 */
class shopPrefillPluginOrderHooks
{
    private shopPrefillPluginSessionStorageProvider $session_storage;
    private shopPrefillPluginOrderProvider $order_provider;
    private shopPrefillPluginGuestHashStorage $guest_hash_storage;
    private shopPrefillPluginZenMode $zen_mode;
    private shopPrefillPluginUserProvider $user_provider;
    private shopPrefillPluginConsentStorage $consent_storage;
    private array $storefront_settings;
    private waRequest $request;

    public function __construct(
        shopPrefillPluginSessionStorageProvider $session_storage,
        shopPrefillPluginOrderProvider $order_provider,
        shopPrefillPluginGuestHashStorage $guest_hash_storage,
        shopPrefillPluginZenMode $zen_mode,
        shopPrefillPluginUserProvider $user_provider,
        shopPrefillPluginConsentStorage $consent_storage,
        array $storefront_settings,
        waRequest $request
    ) {
        $this->session_storage = $session_storage;
        $this->order_provider = $order_provider;
        $this->guest_hash_storage = $guest_hash_storage;
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

        $order_id = (int) $data['order_id'];

        // Операции предзаполнения выполняем всегда для активной витрины:
        // настройка prefill.active больше не используется.
        $checkout_params = $this->session_storage->getCheckoutParams();

        // Сохраняем shipping_type_id (для предзаполнения следующего заказа)
        $this->saveShippingType($order_id, $checkout_params);

        // Для неавторизованных: сохраняем хеш гостя
        $this->saveGuestHash($order_id);

        // Очищаем cookies Zen Mode
        $this->zen_mode->clearCookies();

        shopPrefillPluginLog::info('Order creation hook processed successfully', [
            'order_id' => $order_id
        ]);
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
     * Сохраняет хеш гостя для неавторизованных пользователей
     * Логика: если согласие не требуется ИЛИ оно получено - сохраняем хеш
     *
     * @param int $order_id ID заказа
     * @throws waException
     * @throws waDbException
     */
    private function saveGuestHash(int $order_id): void
    {
        if ($this->user_provider->isAuth()) {
            return;
        }

        if (!$this->storefront_settings['prefill']['guest']['enabled']) {
            shopPrefillPluginLog::info('Skipping saveGuestHash: guest prefill is disabled');
            return;
        }

        $consent_required = $this->storefront_settings['prefill']['guest']['consent_required'];
        $has_consent = $this->consent_storage->hasConsent();

        // Сохраняем хеш если: согласие не требуется ИЛИ оно получено
        if (!$consent_required || $has_consent) {
            $guest_hash = $this->guest_hash_storage->getOrCreateGuestHash();
            $this->guest_hash_storage->saveGuestHashToOrder($order_id, $guest_hash);
        }
    }

}
