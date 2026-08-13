<?php

class shopPrefillPluginStorefrontProvider
{
    /** Код глобальной витрины: настройки, общие для всех витрин */
    public const GLOBAL_CODE = '*';

    /** Единственный экземпляр на весь запрос — читает конфиг и строит схему настроек один раз */
    private ?shopPrefillPluginStorefrontSettingProvider $setting_provider = null;

    private ?shopPrefillPluginStorefront $global_storefront = null;

    /** Коллекции витрин по флагу $default: роутинг читается и объекты строятся один раз за запрос */
    private array $collections = [];

    /**
     * @throws waException
     */
    public function getStorefronts(bool $default = false): shopPrefillPluginStorefrontCollection
    {
        $key = (int) $default;

        if (isset($this->collections[$key])) {
            return $this->collections[$key];
        }

        $storefront_collection = new shopPrefillPluginStorefrontCollection();
        $routes = wa()->getRouting()->getByApp(shopPrefillPlugin::APP_ID);

        if ($default) {
            $storefront_collection->add($this->getGlobalStorefront());
        }

        foreach ($routes as $domain => $domain_routes) {
            foreach ($domain_routes as $route) {
                $storefront = new shopPrefillPluginStorefront(
                    $domain,
                    $route['url'],
                    $this->getSettingProvider(),
                    $route
                );
                $storefront_collection->add($storefront);
            }
        }

        return $this->collections[$key] = $storefront_collection;
    }

    /**
     * Глобальная витрина '*' — конструируется, а не ищется в коллекции: она существует всегда.
     */
    public function getGlobalStorefront(): shopPrefillPluginStorefront
    {
        return $this->global_storefront ??= new shopPrefillPluginStorefront(
            self::GLOBAL_CODE,
            self::GLOBAL_CODE,
            $this->getSettingProvider()
        );
    }

    /**
     * Строгий поиск витрины по коду: null, если такой витрины нет.
     * Используй в админке, чтобы отличить несуществующую витрину от глобальных настроек.
     *
     * @throws waException
     */
    public function findStorefront(?string $storefront_code): ?shopPrefillPluginStorefront
    {
        if (empty($storefront_code)) {
            return null;
        }

        if ($storefront_code === self::GLOBAL_CODE) {
            return $this->getGlobalStorefront();
        }

        return $this->getStorefronts()->getByCode($storefront_code);
    }

    /**
     * Витрина текущего запроса или null: в бэкенде, API и CLI роутинг витрины не задиспатчен,
     * на фронтенде другого приложения (сайт, блог) — задиспатчен чужой маршрут.
     *
     * @throws waException
     */
    public function findCurrentStorefront(): ?shopPrefillPluginStorefront
    {
        $routing = wa()->getRouting();
        $url = $routing->getRoute('url');

        if ($url === null) {
            return null;
        }

        $storefront_code = base64_encode($routing->getDomain() . '/' . $url);

        return $this->getStorefronts()->getByCode($storefront_code);
    }

    /**
     * @throws waException
     */
    public function hasCurrentStorefront(): bool
    {
        return $this->findCurrentStorefront() !== null;
    }

    private function getSettingProvider(): shopPrefillPluginStorefrontSettingProvider
    {
        return $this->setting_provider ??= new shopPrefillPluginStorefrontSettingProvider();
    }

}
