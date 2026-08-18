<?php

/**
 * Хранилище гостевого токена предзаполнения
 *
 * Гость идентифицируется случайным bearer-токеном в HTTP-only куке. В заказе хранится
 * не сам токен, а производный от него lookup id — в колонке `shop_order_params.name`,
 * по которой у таблицы есть индекс:
 *
 *     кука:   prefill_guest_token = <64 hex>            (bin2hex(random_bytes(32)))
 *     в БД:   name = 'prefill_guest_' . <48 hex>, value = '1'
 *
 * Почему так, а не значением в колонке `value`: по `value` индекса нет, и поиск гостя
 * перебирал все гостевые заказы магазина (issue-63). Поиск по `name` — точное равенство
 * по существующему индексу.
 *
 * Почему в БД производный id, а не сам токен: сырой токен остаётся только в куке, поэтому
 * утечка или показ параметров заказа не позволяет восстановить чужой идентификатор.
 *
 * Кука выдаётся ТОЛЬКО при первом завершённом заказе: посетитель, который ничего не заказывал,
 * не получает идентификатор за просмотр каталога, а отсутствие куки — бесплатный признак
 * «истории заведомо нет».
 */
class shopPrefillPluginGuestTokenStorage
{
    /** Префикс имени параметра заказа. 14 символов + 48 hex = 62 ≤ varchar(64) */
    private const PARAM_PREFIX = 'prefill_guest_';

    /** Длина hex-части lookup id: 48 символов = 192 бита усечённого SHA-256 */
    private const LOOKUP_ID_LENGTH = 48;

    /** Название куки с токеном */
    private const TOKEN_COOKIE = 'prefill_guest_token';

    /** Время жизни куки в секундах (1 год) */
    private const COOKIE_TTL = 365 * 86400;

    /** Токен принимается только в этом виде; всё остальное считается отсутствующим */
    private const TOKEN_PATTERN = '/\A[a-f0-9]{64}\z/';

    private shopPrefillPluginUserProvider $user_provider;
    private shopOrderParamsModel $order_params_model;
    private waResponse $response;

    public function __construct(
        shopPrefillPluginUserProvider $user_provider,
        shopOrderParamsModel $order_params_model,
        waResponse $response
    ) {
        $this->user_provider      = $user_provider;
        $this->order_params_model = $order_params_model;
        $this->response           = $response;
    }

    public function getUserProvider(): shopPrefillPluginUserProvider
    {
        return $this->user_provider;
    }

    public function getResponse(): waResponse
    {
        return $this->response;
    }

    private function getOrderParamsModel(): shopOrderParamsModel
    {
        return $this->order_params_model;
    }

    /**
     * Возвращает валидный токен из куки или null.
     *
     * Значение, не прошедшее проверку формата, трактуется как отсутствие куки:
     * подделка приводит к индексному промаху, а не к ошибке.
     */
    public function getToken(): ?string
    {
        $token = waRequest::cookie(self::TOKEN_COOKIE, null, waRequest::TYPE_STRING);

        if (!is_string($token) || !preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }

        return $token;
    }

    public function hasToken(): bool
    {
        return $this->getToken() !== null;
    }

    /**
     * Продлевает куку, если она уже есть. Новую не создаёт.
     *
     * Вызывается на каждом визите из frontend_head: срок жизни отсчитывается
     * от последнего посещения, а не от первого заказа.
     */
    public function extendToken(): void
    {
        $token = $this->getToken();
        if ($token === null) {
            return;
        }

        $this->setTokenCookie($token);
        shopPrefillPluginLog::debug('Guest token extended', ['lookup_prefix' => substr($this->getLookupId($token), 0, 8)]);
    }

    /**
     * Возвращает существующий токен или выпускает новый.
     *
     * Единственная точка создания — оформление заказа (OrderHooks::saveGuestLink()).
     */
    public function getOrCreateToken(): string
    {
        $token = $this->getToken();
        if ($token !== null) {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $this->setTokenCookie($token);
        shopPrefillPluginLog::info('Guest token created', ['lookup_prefix' => substr($this->getLookupId($token), 0, 8)]);

        return $token;
    }

    /**
     * Производный идентификатор для имени параметра заказа.
     *
     * Усечение SHA-256 до 192 бит: восстановить токен по нему нельзя,
     * а коллизии при таком размере практически невозможны.
     */
    public function getLookupId(string $token): string
    {
        return substr(hash('sha256', $token), 0, self::LOOKUP_ID_LENGTH);
    }

    /**
     * Имя параметра заказа для переданного токена (или для токена из куки).
     *
     * @return string|null null, если валидной куки нет — обращаться к БД не за чем
     */
    public function getParamName(?string $token = null): ?string
    {
        $token ??= $this->getToken();
        if ($token === null) {
            return null;
        }

        return self::PARAM_PREFIX . $this->getLookupId($token);
    }

    /**
     * Привязывает заказ к гостю: пишет производное имя в параметры заказа.
     *
     * Значение параметра несёт смысл только фактом своего существования,
     * поэтому это константа, а не данные.
     */
    public function linkOrder(int $order_id, string $token): bool
    {
        if ($order_id <= 0) {
            return false;
        }

        $param_name = $this->getParamName($token);
        if ($param_name === null) {
            return false;
        }

        $result = $this->getOrderParamsModel()->setOne($order_id, $param_name, '1');
        shopPrefillPluginLog::info('Guest order link saved', [
            'order_id'      => $order_id,
            'lookup_prefix' => substr($this->getLookupId($token), 0, 8),
        ]);

        return $result;
    }

    /**
     * Удаляет куку с токеном.
     */
    public function clearToken(): void
    {
        shopPrefillPluginLog::info('Guest token cleared');
        $this->getResponse()->setCookie(
            self::TOKEN_COOKIE,
            '',
            [
                'expires'  => time() - 3600,
                'secure'   => waRequest::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Удаляет связи гостя с заказами.
     *
     * Вызывается при отзыве согласия и очистке истории — до того, как кука стёрта:
     * после её потери строки становятся недостижимыми навсегда. Удаление идёт
     * по тому же индексу `name`, что и поиск.
     */
    public function deleteOrderLinks(?string $token = null): void
    {
        $param_name = $this->getParamName($token);
        if ($param_name === null) {
            return;
        }

        $this->getOrderParamsModel()->deleteByField('name', $param_name);
        shopPrefillPluginLog::info('Guest order links deleted');
    }

    /**
     * Полная забывчивость: сначала данные в БД, потом кука.
     */
    public function forget(): void
    {
        $this->deleteOrderLinks();
        $this->clearToken();
    }

    private function setTokenCookie(string $token): void
    {
        $this->getResponse()->setCookie(
            self::TOKEN_COOKIE,
            $token,
            [
                'expires'  => time() + self::COOKIE_TTL,
                'secure'   => waRequest::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    public static function getTokenCookieName(): string
    {
        return self::TOKEN_COOKIE;
    }
}
