<?php

class shopPrefillPluginFillParamsStorage
{
    private shopPrefillPluginUserProvider $user_provider;
    private shopPrefillPluginFillParamsProvider $fill_params_provider;
    private waResponse $response;

    public function __construct(
        shopPrefillPluginUserProvider $user_provider,
        waResponse $response
    ) {
        $this->user_provider = $user_provider;
        $this->response = $response;
    }


    public function getUserProvider(): shopPrefillPluginUserProvider
    {
        return $this->user_provider;
    }

    public function getResponse(): waResponse
    {
        return $this->response;
    }

    private function getFillParamsCachePath(): string
    {
        return 'plugins/prefill/checkout_params_' . $this->getFillParamsCacheId();
    }

    private function getFillParamsCacheId()
    {
        $fill_params_cache_id = waRequest::cookie('checkout_fill_params_cache_id', null, waRequest::TYPE_STRING);

        if ($fill_params_cache_id) {
            return $fill_params_cache_id;
        }

        if ($this->getUserProvider()->isAuth()) {
            $fill_params_cache_id = 'user_' . $this->getUserProvider()->getId();
        } else {
            $fill_params_cache_id = 'guest_' . uniqid() . microtime(true);
        }

        $fill_params_cache_id = hash('sha256', $fill_params_cache_id);

        $this->getResponse()->setCookie('checkout_fill_params_cache_id', $fill_params_cache_id, time() + 300);


        return $fill_params_cache_id;
    }

    public function storeFillParams(shopPrefillPluginFillParams $fill_params): void
    {
        $cache = new waSerializeCache($this->getFillParamsCachePath());
        $cache->set($fill_params);
    }

    public function getStoredFillParams(): ?shopPrefillPluginFillParams
    {
        $cache = new waSerializeCache($this->getFillParamsCachePath());
        if ($cache->isCached()) {
            return $cache->get();
        }

        return null;
    }

}
