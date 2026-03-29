<?php

class shopPrefillPluginStorefrontCollection
{
    private array $storefronts = [];

    public function has(string $storefront_code): bool
    {
        return isset($this->storefronts[$storefront_code]);
    }

    public function add(shopPrefillPluginStorefront $storefront)
    {
        $storefront_code = $storefront->getCode();

        if (!$this->has($storefront_code)) {
            $this->storefronts[$storefront_code] = $storefront;
        }
    }

    public function getList(): array
    {
        return array_values($this->storefronts);
    }

    public function getTree(): array
    {
        $storefronts_tree = [];
        $storefront_list = $this->getList();

        foreach ($storefront_list as $storefront) {
            $domain = $storefront->getDomain();
            $url = $storefront->getUrl();

            if (!isset($storefronts_tree[$domain])) {
                $storefronts_tree[$domain] = [];
            }

            $storefronts_tree[$domain][$url] = $storefront;
        }

        return $storefronts_tree;
    }

    /**
     * @throws waDbException
     */
    public function toJson(): string
    {
        $storefront_groups = [];
        $statuses = ['*' => true];
        $storefront_list = $this->getList();

        foreach ($storefront_list as $storefront) {
            $domain = $storefront->getDomain();

            if (!isset($storefront_groups[$domain])) {
                $storefront_groups[$domain] = [
                    'domain' => $domain,
                    'items'  => [],
                ];
            }

            $storefront_groups[$domain]['items'][] = [
                'code' => $storefront->getCode(),
                'url'  => $storefront->getFullUrl(),
            ];

            $statuses[$storefront->getCode()] = $storefront->isActive();
        }

        return json_encode([
            'storefronts' => array_values($storefront_groups),
            'statuses'    => $statuses,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function getByCode(string $storefront_code): ?shopPrefillPluginStorefront
    {
        if (!$this->has($storefront_code)) {
            return null;
        }

        return $this->storefronts[$storefront_code];
    }

}
