<?php

/**
 * Хранилище согласия пользователя на сохранение данных для предзаполнения
 *
 * Управляет кукой prefill_consent:
 * - '1' = согласие дано
 * - отсутствие куки = нет согласия
 *
 * Cookie продлевается на 1 год явным вызовом `renewConsentIfGranted()` — единственная точка
 * вызова: `FrontendHooks::handleGuestCookies()`, на каждом посещении витрины гостем. Согласие
 * не должно «внезапно» истечь у активного пользователя, а логика продления не должна
 * размазываться по нескольким местам (единая точка правды).
 *
 * До 09.09.2026 продление было побочным эффектом самого `hasConsent()`, и её звали в трёх
 * разных хуках одного запроса (`frontend_head`, `checkout_render_confirm`,
 * `order_action.create`) — каждый читал согласие и заодно молча продлевал TTL, из-за чего
 * полная загрузка `/order/` слала два одинаковых `Set-Cookie: prefill_consent` (issue-100 §1).
 * `hasConsent()` теперь чистое чтение; продление — их общий явный сосед.
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
     * Важно держать синхронно с TTL гостевого токена (prefill_guest_token),
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
     * Проверяет наличие согласия пользователя (гостя). Чистое чтение, без побочных эффектов —
     * продление TTL здесь больше не происходит (issue-100 §1), см. `renewConsentIfGranted()`.
     *
     * @return bool true, если согласие дано
     */
    public function hasConsent(): bool
    {
        $has_consent = waRequest::cookie(self::CONSENT_COOKIE) === '1';

        shopPrefillPluginLog::debug($has_consent
            ? 'Guest consent check: consent present'
            : 'Guest consent check: no consent');

        return $has_consent;
    }

    /**
     * Продлевает TTL куки согласия, если оно дано. Единственная точка вызова —
     * `FrontendHooks::handleGuestCookies()`, на каждом посещении витрины гостем: там она
     * заменила прежний вызов `hasConsent()`, звавшийся ради этого же побочного эффекта.
     */
    public function renewConsentIfGranted(): void
    {
        if (!$this->hasConsent()) {
            return;
        }

        $this->renewConsent();
        shopPrefillPluginLog::debug('Guest consent TTL renewed');
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
     * `httponly=true` обязателен, чтобы JS не мог прочитать куку.
     */
    private function setConsentCookie(int $expires): void
    {
        $this->response->setCookie(self::CONSENT_COOKIE, '1', [
            'expires'  => $expires,
            'secure'   => waRequest::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Удаляет cookie согласия.
     */
    private function deleteConsentCookie(): void
    {
        $this->response->setCookie(self::CONSENT_COOKIE, '', [
            'expires'  => time() - 3600,
            'secure'   => waRequest::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
