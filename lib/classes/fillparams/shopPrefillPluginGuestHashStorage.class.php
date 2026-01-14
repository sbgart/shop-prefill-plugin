<?php

/**
 * Хранилище хеша гостя для предзаполнения
 *
 * Управляет уникальным идентификатором гостя (prefill_guest_hash):
 * - Генерирует и хранит хеш в HTTP-only куки (1 год, автопродление)
 * - Сохраняет хеш в shop_order_params при оформлении заказа
 *
 * Для авторизованных пользователей хеш не используется — данные берутся по contact_id
 */
class shopPrefillPluginGuestHashStorage
{
    /** Название параметра в shop_order_params */
    private const GUEST_HASH_PARAM = 'prefill_guest_hash';

    /** Название куки для хеша гостя */
    private const GUEST_HASH_COOKIE = 'prefill_guest_hash';

    /** Время жизни куки в секундах (1 год) */
    private const COOKIE_TTL = 365 * 86400;

    private shopPrefillPluginUserProvider $user_provider;
    private shopOrderParamsModel          $order_params_model;
    private waResponse                    $response;

    public function __construct(
        shopPrefillPluginUserProvider $user_provider,
        shopOrderParamsModel $order_params_model,
        waResponse $response
    ) {
        $this->user_provider      = $user_provider;
        $this->order_params_model = $order_params_model;
        $this->response           = $response;
    }

    /**
     * Возвращает провайдер пользователя
     */
    public function getUserProvider(): shopPrefillPluginUserProvider
    {
        return $this->user_provider;
    }

    /**
     * Возвращает модель параметров заказа
     */
    private function getOrderParamsModel(): shopOrderParamsModel
    {
        return $this->order_params_model;
    }

    /**
     * Возвращает объект ответа Webasyst
     */
    public function getResponse(): waResponse
    {
        return $this->response;
    }

    /**
     * Получает или создает хеш гостя
     *
     * Логика:
     * 1. Если куки есть — продлеваем и возвращаем
     * 2. Если куки нет — генерируем новый хеш, сохраняем в куки
     *
     * @return string Хеш гостя (SHA256, 64 символа)
     */
    public function getOrCreateGuestHash(): string
    {
        $guest_hash = waRequest::cookie(self::GUEST_HASH_COOKIE, null, waRequest::TYPE_STRING);

        if ($guest_hash) {
            // Продлеваем куки при каждом обращении
            $this->setGuestHashCookie($guest_hash);
            return $guest_hash;
        }

        // Генерируем новый уникальный хеш
        $unique_id  = 'guest_' . uniqid('', true) . '_' . microtime(true);
        $guest_hash = hash('sha256', $unique_id);

        $this->setGuestHashCookie($guest_hash);

        return $guest_hash;
    }

    /**
     * Возвращает текущий хеш гостя из куки (без создания нового)
     *
     * @return string|null Хеш гостя или null если куки нет
     */
    public function getGuestHash(): ?string
    {
        return waRequest::cookie(self::GUEST_HASH_COOKIE, null, waRequest::TYPE_STRING);
    }

    /**
     * Устанавливает куку с хешем гостя
     *
     * @param string $hash Хеш для сохранения
     */
    private function setGuestHashCookie(string $hash): void
    {
        $this->getResponse()->setCookie(
            self::GUEST_HASH_COOKIE,
            $hash,
            time() + self::COOKIE_TTL,
            null,   // path (default)
            '',     // domain (default)
            false,  // secure (TODO: включить для production)
            true    // httponly — защита от XSS
        );
    }

    /**
     * Сохраняет хеш гостя в параметры заказа
     *
     * Вызывается при оформлении заказа неавторизованным пользователем.
     * Позволяет потом найти все заказы этого гостя по хешу.
     *
     * @param int    $order_id ID заказа
     * @param string $hash     Хеш гостя
     * @return bool Успешность сохранения
     */
    public function saveGuestHashToOrder(int $order_id, string $hash): bool
    {
        if ($order_id <= 0 || empty($hash)) {
            return false;
        }

        return $this->getOrderParamsModel()->setOne($order_id, self::GUEST_HASH_PARAM, $hash);
    }

    /**
     * Возвращает название параметра хеша гостя
     *
     * @return string
     */
    public static function getGuestHashParamName(): string
    {
        return self::GUEST_HASH_PARAM;
    }

    /**
     * Возвращает название куки хеша гостя
     *
     * @return string
     */
    public static function getGuestHashCookieName(): string
    {
        return self::GUEST_HASH_COOKIE;
    }
}
