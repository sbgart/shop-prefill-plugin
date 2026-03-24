<?php

class shopPrefillPluginLocationProvider
{
    private waCountryModel $country_model;
    private waRegionModel  $region_model;
    /** @var array<string, string|null> */
    private static array $country_name_cache = [];
    /** @var array<string, string|null> */
    private static array $region_name_cache = [];

    public function __construct(waCountryModel $country_model, waRegionModel $region_model)
    {
        $this->country_model = $country_model;
        $this->region_model  = $region_model;
    }

    private function getCountryModel(): waCountryModel
    {
        return $this->country_model;
    }

    private function getRegionModel(): waRegionModel
    {
        return $this->region_model;
    }

    public function getCountries($group = false): array
    {
        return $this->getCountryModel()->allWithFav();
    }

    public function getCountryName($country): ?string
    {
        $country_key = (string) $country;
        if (! array_key_exists($country_key, self::$country_name_cache)) {
            self::$country_name_cache[$country_key] = $this->getCountryModel()->name($country);
        }

        return self::$country_name_cache[$country_key];
    }

    public function getRegions($country, $group = false)
    {
        $method = $group ? 'getByCountry' : 'getByCountryWithFav';

        return $this->getRegionModel()->$method($country);
    }

    public function getRegionName($country, $region)
    {
        $region_key = (string) $country . ':' . (string) $region;
        if (! array_key_exists($region_key, self::$region_name_cache)) {
            $region_data                          = $this->getRegionModel()->get($country, $region);
            self::$region_name_cache[$region_key] = ifset($region_data, 'name', null);
        }

        return self::$region_name_cache[$region_key];
    }
}
