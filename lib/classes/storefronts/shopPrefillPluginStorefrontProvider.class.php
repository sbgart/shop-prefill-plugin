<?php

class shopPrefillPluginStorefrontProvider
{
    /**
     * @throws waException
     */
    public function getStorefronts(bool $default = false): shopPrefillPluginStorefrontCollection
    {
        $storefront_collection = new shopPrefillPluginStorefrontCollection();
        $routes = wa()->getRouting()->getByApp(shopPrefillPlugin::APP_ID);

        // Единственный экземпляр на весь запрос — читает конфиг и строит структуру один раз
        $setting_provider = new shopPrefillPluginStorefrontSettingProvider();

        if ($default) {
            $storefront_collection->add(new shopPrefillPluginStorefront('*', '*', $setting_provider));
        }

        foreach ($routes as $domain => $domain_routes) {
            foreach ($domain_routes as $route) {
                $storefront = new shopPrefillPluginStorefront($domain, $route['url'], $setting_provider, $route);
                $storefront_collection->add($storefront);
            }
        }

        return $storefront_collection;
    }

    /**
     * @throws waException
     */
    public function getStorefront($storefront_code): shopPrefillPluginStorefront
    {
        $storefronts = $this->getStorefronts(true);

        return $storefronts->getByCode($storefront_code);
    }

    /**
     * @throws waException
     */
    public function getCurrentStorefront(): ?shopPrefillPluginStorefront
    {
        $storefronts = $this->getStorefronts();

        $routing = wa()->getRouting();
        $domain = $routing->getDomain();
        $url = $routing->getRoute('url');

        $storefront_code = base64_encode($domain . '/' . $url);

        return $storefronts->getByCode($storefront_code);
    }

}
