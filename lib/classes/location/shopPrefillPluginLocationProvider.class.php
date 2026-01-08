<?php

class shopPrefillPluginLocationProvider
{
    private waCountryModel $country_model;
    private waRegionModel $region_model;

    public function __construct(waCountryModel $country_model, waRegionModel $region_model)
    {
        $this->country_model = $country_model;
        $this->region_model = $region_model;
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
        return $this->getCountryModel()->name($country);
    }

    public function getRegions($country, $group = false)
    {
        $method = $group ? 'getByCountry' : 'getByCountryWithFav';

        return $this->getRegionModel()->$method($country);
    }

    public function getRegionName($country, $region)
    {
        $region = $this->getRegionModel()->get($country, $region);

        return ifset($region, 'name', null);
    }
}