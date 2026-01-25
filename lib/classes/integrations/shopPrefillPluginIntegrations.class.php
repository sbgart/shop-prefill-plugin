<?php

class shopPrefillPluginIntegrations
{
    // TODO: Скорее всего надо что плагин запускался раньше всех остальных плагинов.
    // TODO: Надо проверить, интеграция запускается только один раз, потому как иначе мы не будем давать выбрать другой город. Через куки "prefill_integrated_init"

    /**
     * Integration with CitySelect plugin.
     * Sets CitySelect cookies based on prefill data to ensure region section is populated.
     * CitySelect normally only prefills once per user, but this integration allows
     * prefill to "reuse" CitySelect functionality for region data.
     *
     * @param  ?shopPrefillPluginFillParams  $params
     *
     * @return void
     * @throws waException
     */
    public static function cityselect(?shopPrefillPluginFillParams $params): void
    {
        if (! shopPrefillPlugin::enableInstall('cityselect')) {
            return;
        }

        if (is_null($params)) {
            return;
        }

        if (waRequest::cookie('prefill_cityselect')) {
            return;
        }

        self::setCookies([
            'cityselect__country' => $params->getCountry(),
            'cityselect__region'  => $params->getRegion(),
            'cityselect__city'    => $params->getCity(),
            'cityselect__zip'     => $params->getZip(),
            'prefill_cityselect'  => true,
        ]);
    }

    /**
     * @param  ?shopPrefillPluginFillParams  $fill_params
     *
     * @return void
     * @throws waException
     */
    public static function dp(?shopPrefillPluginFillParams $fill_params): void
    {
        if (! shopPrefillPlugin::enableInstall('dp')) {
            return;
        }

        if (is_null($fill_params)) {
            return;
        }

        if (waRequest::cookie('prefill_dp')) {
            return;
        }

        self::setCookies([
            'dp_plugin_country' => $fill_params->getCountry(),
            'dp_plugin_region'  => $fill_params->getRegion(),
            'dp_plugin_city'    => $fill_params->getCity(),
            'dp_plugin_zip'     => $fill_params->getZip(),
            'prefill_dp'        => true,
        ]);
    }

    /**
     * @throws waException
     */
    private static function setCookies(array $cookies): void
    {
        $response = wa()->getResponse();

        foreach ($cookies as $name => $value) {
            $response->setCookie($name, $value, time() + 12 * 30 * 86400);
        }
    }
}
