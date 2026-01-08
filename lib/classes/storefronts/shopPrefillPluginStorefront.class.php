<?php

class shopPrefillPluginStorefront
{

    private string $domain;
    private string $url;
    private string $code;
    private array $route;

    private shopPrefillPluginStorefrontSettingProvider $setting_provider;

    public function __construct(string $domain, string $url, array $route = [])
    {
        $this->domain = $domain;
        $this->url = $url;
        $this->code = $domain === '*' && $url === '*' ? '*' : base64_encode($domain . '/' . $url);
        $this->route = $route;
        $this->setting_provider = new shopPrefillPluginStorefrontSettingProvider();
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
     * @throws waException
     */
    public function getRouteUrl(string $path, array $params = [], bool $absolute = false): ?string
    {
        $domain = $this->getDomain();
        $url = $this->getRoute('url');

        return wa()->getRouting()->getUrl($path, $params, $absolute, $domain, $url);
    }

    /**
     * @throws waDbException
     */
    public function getSettings(): array
    {
        $storefront_code = $this->code;

        if (!$this->isActive()) {
            $storefront_code = '*';
        }

        return $this->setting_provider->getSettings($storefront_code);
    }

    /**
     * @throws waException
     */
    public function setSetting($key, $value, $groups = null)
    {
        $this->setting_provider->setSetting($this->code, $key, $value, $groups);
    }

    /**
     * @throws waException
     */
    public function saveSettings($settings = [])
    {
        $this->setting_provider->saveSettings($this->code, $settings);
    }

    /**
     * @throws waDbException
     */
    public function isActive(): bool
    {
        return $this->setting_provider->getSettings($this->code)['active'];
    }
}
