<?php

class shopPrefillPluginUserProvider
{
    /** Стандартный срок жизни auth_token в Webasyst, см. waAuth::_remember() */
    private const DEFAULT_TTL = 2592000;

    private waAuthUser $user;
    private ?int       $id     = null;
    private ?bool      $isAuth = null;

    public function __construct(waAuthUser $user)
    {
        $this->user = $user;
    }

    public function getUser(): waAuthUser
    {
        return $this->user;
    }

    public function getId(): ?int
    {
        return $this->id ??= $this->getUser()->getId();
    }

    public function isAuth(): bool
    {
        return $this->isAuth ??= $this->getUser()->isAuth();
    }

    /**
     * Выдал ли фреймворк постоянный токен авторизации.
     *
     * Единственный надёжный признак того, что покупатель сам отметил «Запомнить меня».
     * Cookie `remember` для этого не годится: waAuth::_remember() пишет в неё 0 и тем,
     * кто снял галочку, и тем, кому её вообще не показывали (авторизация при заказе).
     */
    public function hasAuthToken(): bool
    {
        return (bool) waRequest::cookie('auth_token');
    }

    /**
     * Включён ли «Запомнить меня» на домене витрины.
     *
     * Без него waAuth::_authByCookie() не читает auth_token вовсе — токен становится
     * бесполезным, поэтому выдавать его в этом случае незачем.
     */
    public function isDomainRememberMeEnabled(): bool
    {
        $config = waDomainAuthConfig::factory();

        return $config && $config->getRememberMe();
    }

    /**
     * Ставит или продлевает cookie авторизации фреймворка.
     *
     * @param int $expires_days 0 — стандартный срок Webasyst, > 0 — кастомный срок в днях
     * @throws waException
     */
    public function rememberMe(int $expires_days = 0): void
    {
        if (! $this->isAuth()) {
            return;
        }

        $token = $this->getAuthToken();
        if ($token === '') {
            return;
        }

        $ttl = $expires_days > 0 ? $expires_days * 86400 : self::DEFAULT_TTL;

        // Cookie `remember` намеренно не ставим: она лишь предотмечает галочку в форме
        // логина, а покупатель мог никакого выбора и не делать.
        waSystem::getInstance()->getResponse()->setCookie(
            'auth_token',
            $token,
            time() + $ttl,
            null,
            '',
            waRequest::isHttps(),
            true
        );
    }

    /**
     * Токен считает сам фреймворк — не дублируем md5-формулу waAuth::getToken()
     * и не читаем password контакта напрямую.
     */
    private function getAuthToken(): string
    {
        $auth = waSystem::getInstance()->getAuth();
        if (! method_exists($auth, 'getToken')) {
            return '';
        }

        return (string) $auth->getToken($this->getUser());
    }
}
