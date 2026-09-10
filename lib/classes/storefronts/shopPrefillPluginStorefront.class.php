<?php

class shopPrefillPluginStorefront
{
    private string $domain;
    private string $url;
    private string $code;
    private array $route;

    private shopPrefillPluginStorefrontSettingProvider $setting_provider;

    public function __construct(
        string $domain,
        string $url,
        shopPrefillPluginStorefrontSettingProvider $setting_provider,
        array $route = []
    ) {
        $this->domain           = $domain;
        $this->url              = $url;
        $this->code             = shopPrefillPluginStorefrontCode::fromRoute($domain, $url, $route);
        $this->route            = $route;
        $this->setting_provider = $setting_provider;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getFullUrl(): string
    {
        return $this->domain . '/' . $this->url;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getRoute(string $name = null, string $default = null)
    {
        if (!empty($name)) {
            return ifset($this->route[$name], $default);
        }

        return $this->route;
    }

    /**
     * Always returns this storefront's own settings — no fallback to global.
     *
     * @throws waDbException
     */
    public function getSettings(): array
    {
        return $this->setting_provider->getSettings($this->code);
    }

    /**
     * @throws waException
     */
    public function saveSettings(array $settings = []): void
    {
        $this->setting_provider->saveSettings($this->code, $settings);
    }

    /**
     * @throws waDbException
     */
    public function isActive(): bool
    {
        return (bool) $this->getSettings()['active'];
    }
}
