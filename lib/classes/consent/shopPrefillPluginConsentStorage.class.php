<?php

/**
 * Хранилище согласия пользователя на сохранение данных для предзаполнения
 *
 * Управляет кукой prefill_consent:
 * - '1' = согласие дано
 * - отсутствие куки = нет согласия
 *
 * Cookie автоматически продлевается на 1 год при каждой проверке hasConsent(),
 * гарантируя, что согласие не истечёт, пока пользователь посещает сайт.
 *
 * Используется только для гостей. Авторизованные пользователи
 * идентифицируются по contact_id, согласие не требуется.
 */
class shopPrefillPluginConsentStorage
{
    /** Название куки для согласия */
    private const CONSENT_COOKIE = 'prefill_consent';

    /** Время жизни куки в секундах (1 год) */
    private const COOKIE_TTL = 365 * 86400;

    private waResponse $response;

    public function __construct(waResponse $response)
    {
        $this->response = $response;
    }

    /**
     * Проверяет наличие согласия пользователя
     *
     * При наличии согласия автоматически продлевает срок жизни cookie на 1 год.
     * Это обеспечивает, что согласие не истекает, пока пользователь посещает сайт.
     *
     * @return bool true если согласие дано
     */
    public function hasConsent(): bool
    {
        $consent = waRequest::cookie(self::CONSENT_COOKIE, null, waRequest::TYPE_STRING);

        if ($consent === '1') {
            // Продлеваем cookie при каждой проверке
            $this->renewConsent();
            return true;
        }

        return false;
    }

    /**
     * Продлевает срок жизни cookie согласия
     *
     * Вызывается автоматически при каждой проверке hasConsent()
     */
    private function renewConsent(): void
    {
        $this->response->setCookie(
            self::CONSENT_COOKIE,
            '1',
            time() + self::COOKIE_TTL,
            null,   // path (default)
            '',     // domain (default)
            false,  // secure (TODO: включить для production)
            true    // httponly — защита от XSS
        );
    }

    /**
     * Выдает согласие (устанавливает куку)
     */
    public function grantConsent(): void
    {
        $this->response->setCookie(
            self::CONSENT_COOKIE,
            '1',
            time() + self::COOKIE_TTL,
            null,   // path (default)
            '',     // domain (default)
            false,  // secure (TODO: включить для production)
            true    // httponly — защита от XSS
        );
    }

    /**
     * Отзывает согласие (удаляет куку)
     */
    public function revokeConsent(): void
    {
        $this->response->setCookie(
            self::CONSENT_COOKIE,
            '',
            time() - 3600, // Устанавливаем время в прошлом для удаления
            null,   // path (default)
            '',     // domain (default)
            false,  // secure (TODO: включить для production)
            true    // httponly — защита от XSS
        );
    }

    /**
     * Возвращает название куки согласия
     *
     * @return string
     */
    public static function getConsentCookieName(): string
    {
        return self::CONSENT_COOKIE;
    }
}
