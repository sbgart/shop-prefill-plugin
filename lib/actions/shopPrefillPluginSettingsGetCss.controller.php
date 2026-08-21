<?php

class shopPrefillPluginSettingsGetCssController extends shopPrefillPluginSettingsBaseController
{
    /**
     * @throws waException
     * @throws waDbException
     */
    protected function handle()
    {
        waLocale::loadByDomain(['shop', 'prefill']);
        waSystem::pushActivePlugin('prefill', 'shop');

        $storefront_code = waRequest::request('code', '', 'string');

        if ($storefront_code === '') {
            $this->setError('Missing storefront code');
            return;
        }

        $plugin      = shopPrefillPlugin::getInstance();
        $css_manager = $plugin->getCssManager();
        $default     = $css_manager->getDefaultContent();

        // Витрину могли удалить или переименовать после загрузки списка в браузере
        $storefront = $plugin->getStorefrontProvider()->findStorefront($storefront_code);

        if ($storefront === null) {
            $this->setError(_wp('error.storefront_not_found'));
            return;
        }

        $settings   = $storefront->getSettings();
        $custom_css = $settings['styles']['custom_css'] ?? '';

        // custom_css теперь содержит только переопределения — редактор открывается с ним как есть,
        // включая пустую строку (это нормальное стартовое состояние, а не "нет данных")
        if ($custom_css !== '' && !$css_manager->fileExists($storefront_code)) {
            // Файл удалён (очистка wa-data) — восстанавливаем из БД
            $css_manager->saveFile($storefront_code, $custom_css);
            shopPrefillPluginLog::info('CSS file restored from DB on editor open', [
                'storefront_code' => $storefront_code,
            ]);
        } else {
            shopPrefillPluginLog::debug('CSS editor opened', [
                'storefront_code' => $storefront_code,
                'size'            => strlen($custom_css),
            ]);
        }

        $this->response = [
            'current_css' => $custom_css,
            'default_css' => $default,
            'is_custom'   => $custom_css !== '',
        ];
    }
}
