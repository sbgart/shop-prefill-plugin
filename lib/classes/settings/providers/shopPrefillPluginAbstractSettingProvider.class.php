<?php

abstract class shopPrefillPluginAbstractSettingProvider
{
    protected shopPrefillPluginSettingsModel $model;
    protected shopPrefillPluginSettingsConfig $config;

    public function __construct(
        shopPrefillPluginSettingsModel $model,
        shopPrefillPluginSettingsConfig $config
    ) {
        $this->model  = $model;
        $this->config = $config;
    }

    protected function validate(array $settings): array
    {
        return $this->config->getSchema()->validate($settings);
    }
}
