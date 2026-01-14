<?php

class shopPrefillPluginUserProvider
{

    private waAuthUser $user;
    private ?int       $id              = null;
    private ?bool      $isAuth          = null;
    private ?string    $create_datetime = null;
    private ?string    $login           = null;
    private ?string    $password        = null;

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

    public function getCreateDatetime(): string
    {
        return $this->create_datetime ??= $this->getUser()->get('create_datetime') ?? '';
    }

    public function getLogin(): string
    {
        return $this->login ??= $this->getUser()->get('login') ?? '';
    }

    public function getPassword(): string
    {
        return $this->password ??= $this->getUser()->get('password') ?? '';
    }

    /**
     * @throws waException
     */
    public function rememberMe(int $expires = 90): void
    {
        if (! $this->isAuth()) {
            return;
        }

        $response = waSystem::getInstance()->getResponse();
        $response->setCookie(
            'auth_token',
            $this->getAuthToken(),
            time() + ($expires * 86400),
            null,
            '',
            false,
            true
        );
        $response->setCookie('remember', 1);
    }

    private function getAuthToken(): string
    {
        $hash = md5($this->getCreateDatetime() . $this->getLogin() . $this->getPassword());

        return substr($hash, 0, 15) . $this->getId() . substr($hash, -15);
    }
}
