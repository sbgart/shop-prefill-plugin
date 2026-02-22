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

    public function __construct(
        shopPrefillPluginSessionStorageProvider $session_storage,
        shopPrefillPluginOrderProvider $order_provider,
        shopPrefillPluginGuestHashStorage $guest_hash_storage,
        shopPrefillPluginZenMode $zen_mode,
        shopPrefillPluginUserProvider $user_provider,
        shopPrefillPluginConsentStorage $consent_storage,
        array $storefront_settings
    ) {
        $this->session_storage = $session_storage;
        $this->order_provider = $order_provider;
        $this->guest_hash_storage = $guest_hash_storage;
        $this->zen_mode = $zen_mode;
        $this->user_provider = $user_provider;
        $this->consent_storage = $consent_storage;
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

        $order_id = (int) $data['order_id'];
        $checkout_params = $this->session_storage->getCheckoutParams();

        // Сохраняем shipping_type_id
        $this->saveShippingType($order_id, $checkout_params);

        // Сохраняем комментарий
        $this->saveComment($order_id, $checkout_params);

        // Для неавторизованных: сохраняем хеш гостя
        $this->saveGuestHash($order_id);

        // Очищаем cookies Zen Mode
        $this->clearZenModeCookies();

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
        $shipping_type_id = (int) ($checkout_params['order']['shipping']['type_id'] ?? 0);
        $this->order_provider->storeShippingTypeId($order_id, $shipping_type_id);
    }

    /**
     * Сохраняет комментарий заказа
     *
     * @param int $order_id ID заказа
     * @param array $checkout_params Параметры checkout
     */
    private function saveComment(int $order_id, array $checkout_params): void
    {
        $comment = $checkout_params['order']['confirm']['comment'] ?? '';
        $this->order_provider->storeComment($order_id, $comment);
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

        $consent_required = $this->storefront_settings['guest']['consent_required'];
        $has_consent = $this->consent_storage->hasConsent();

        // Сохраняем хеш если: согласие не требуется ИЛИ оно получено
        if (!$consent_required || $has_consent) {
            $guest_hash = $this->guest_hash_storage->getOrCreateGuestHash();
            $this->guest_hash_storage->saveGuestHashToOrder($order_id, $guest_hash);
        }
    }

    /**
     * Очищает cookies Zen Mode после создания заказа
     * Делегирует вызов в ZenHelper
     *
     * @throws waException
     */
    private function clearZenModeCookies(): void
    {
        $this->zen_mode->clearCookies();
    }
}
