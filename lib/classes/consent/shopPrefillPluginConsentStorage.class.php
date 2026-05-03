<?php

/**
 * Хранилище согласия пользователя на сохранение данных для предзаполнения
 *
 * Управляет кукой prefill_consent:
 * - '1' = согласие дано
 * - отсутствие куки = нет согласия
 *
 * Cookie автоматически продлевается на 1 год при каждой проверке `hasConsent()`.
 * Это сделано намеренно: согласие не должно «внезапно» истечь у активного пользователя,
 * и логика продления не должна размазываться по нескольким местам (единая точка правды).
 *
 * Используется только для гостей. Авторизованные пользователи
 * идентифицируются по contact_id, согласие не требуется.
 */
class shopPrefillPluginConsentStorage
{
    public const CONSENT_COOKIE = 'prefill_consent';

    /**
     * TTL куки в секундах (1 год).
     *
     * Важно держать синхронно с TTL гостевого хеша (prefill_guest_hash),
     * чтобы у пары «идентификатор гостя» + «согласие» не было неожиданных рассинхронов.
     */
    private const COOKIE_TTL = 31536000;

    private waResponse $response;

    /**
     * @param waResponse $response Используем response, чтобы выставлять Set-Cookie централизованно.
     */
    public function __construct(waResponse $response)
    {
        $this->response = $response;
    }

    /**
     * Проверяет наличие согласия пользователя (гостя).
     *
     * Если согласие было дано ранее (cookie = '1'), метод также продлевает TTL куки.
     * Это специально сделано побочным эффектом проверки, т.к. `hasConsent()` вызывается
     * в «естественных» точках жизненного цикла (frontend_head / checkout hooks).
     *
     * @return bool true, если согласие дано
     */
    public function hasConsent(): bool
    {
        if (waRequest::cookie(self::CONSENT_COOKIE) !== '1') {
            shopPrefillPluginLog::debug('Guest consent check: no consent');
            return false;
        }

        // Согласие есть — продлеваем TTL на этом же запросе.
        $this->renewConsent();
        shopPrefillPluginLog::debug('Guest consent check: consent present, TTL renewed');
        return true;
    }

    /**
     * Выдаёт согласие (устанавливает/обновляет cookie).
     *
     * Делегирует в `renewConsent()`, чтобы не дублировать параметры `setCookie()`.
     */
    public function grantConsent(): void
    {
        $this->renewConsent();
    }

    /**
     * Отзывает согласие (удаляет cookie).
     *
     * Важно удалять cookie с теми же ключевыми атрибутами (path/domain/etc.),
     * что и при установке — иначе браузер может сохранить «старую» версию.
     */
    public function revokeConsent(): void
    {
        $this->deleteConsentCookie();
    }

    /**
     * Продлевает согласие на следующий период TTL.
     */
    private function renewConsent(): void
    {
        $this->setConsentCookie(time() + self::COOKIE_TTL);
    }

    /**
     * Ставит cookie согласия с нужными атрибутами.
     *
     * `secure=false` сейчас намеренно (см. отдельный issue про secure cookies).
     * `httponly=true` обязателен, чтобы JS не мог прочитать куку.
     */
    private function setConsentCookie(int $expires): void
    {
        $this->response->setCookie(
            self::CONSENT_COOKIE,
            '1',
            $expires,
            null,
            '',
            false,
            true
        );
    }

    /**
     * Удаляет cookie согласия.
     */
    private function deleteConsentCookie(): void
    {
        $this->response->setCookie(
            self::CONSENT_COOKIE,
            '',
            time() - 3600,
            null,
            '',
            false,
            true
        );
    }
}
