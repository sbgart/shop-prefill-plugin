<?php

class shopPrefillPluginStorefrontSettingProvider extends shopPrefillPluginAbstractSettingProvider
{
    public function __construct()
    {
        parent::__construct(
            new shopPrefillPluginSettingsModel(),
            shopPrefillPluginSettingsConfig::create('storefront.settings')
        );
    }

    public function getSettings(string $storefront_code): array
    {
        return $this->validate($this->model->get($storefront_code));
    }

    public function setSetting(string $storefront_code, $key, $value, $groups = null): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $this->setSetting($storefront_code, $k, $v, array_merge((array) $groups, [$key]));
            }
            return;
        }

        // bool → int so false isn't stored as empty string
        if (is_bool($value)) {
            $value = (int) $value;
        }

        $this->model->set($storefront_code, $key, $value, $groups);
    }

    public function saveSettings(string $storefront_code, array $settings = []): void
    {
        foreach ($settings as $key => $value) {
            $this->setSetting($storefront_code, $key, $value);
        }

        $this->setSetting($storefront_code, 'update_time', time());
        $this->setSetting($storefront_code, 'updated_by', wa()->getUser()->getId() ?? 0);

        $this->syncCssFile($storefront_code, $settings);

        shopPrefillPluginLog::info('Storefront settings saved', [
            'storefront_code' => $storefront_code,
            'updated_by'      => wa()->getUser()->getId(),
        ]);
    }

    /**
     * Синхронизирует CSS-файл на диске с сохранённым custom_css.
     * Вызывается только если в $settings передан ключ styles.custom_css.
     *
     * @throws waException
     */
    private function syncCssFile(string $storefront_code, array $settings): void
    {
        $custom_css = $settings['styles']['custom_css'] ?? null;

        if ($custom_css === null) {
            return;
        }

        shopPrefillPlugin::getInstance()->getCssManager()->saveFile($storefront_code, $custom_css);
    }
}
