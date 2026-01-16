<?php

/**
 * Хранилище согласия пользователя на сохранение данных для предзаполнения
 *
 * Управляет кукой prefill_consent:
 * - '1' = согласие дано
 * - отсутствие куки = нет согласия
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
     * @return bool true если согласие дано
     */
    public function hasConsent(): bool
    {
        $consent = waRequest::cookie(self::CONSENT_COOKIE, null, waRequest::TYPE_STRING);

        return $consent === '1';
    }

    /**
     * Выдает согласие (устанавливает куку)
     */
    public function grantConsent(): void
    {
        setcookie(
            self::CONSENT_COOKIE,
            '1',
            time() + self::COOKIE_TTL,
            '/',    // path
            '',     // domain
            false,  // secure (TODO: включить для production)
            true    // httponly — защита от XSS
        );
    }

    /**
     * Отзывает согласие (удаляет куку)
     */
    public function revokeConsent(): void
    {
        setcookie(
            self::CONSENT_COOKIE,
            '',
            time() - 3600, // Устанавливаем время в прошлом для удаления
            '/',
            '',
            false,
            true
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
