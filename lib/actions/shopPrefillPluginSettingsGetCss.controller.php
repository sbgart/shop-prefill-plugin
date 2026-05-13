<?php

class shopPrefillPluginSettingsGetCssController extends waJsonController
{
    /**
     * @throws waException
     * @throws waDbException
     */
    public function execute()
    {
        $storefront_code = waRequest::request('code', '', 'string');

        if ($storefront_code === '') {
            $this->setError('Missing storefront code');
            return;
        }

        $plugin      = shopPrefillPlugin::getInstance();
        $css_manager = $plugin->getCssManager();
        $default     = $css_manager->getDefaultContent();

        $settings   = $plugin->getStorefrontProvider()->getStorefront($storefront_code)->getSettings();
        $custom_css = $settings['styles']['custom_css'] ?? '';

        if ($custom_css !== '') {
            if (!$css_manager->fileExists($storefront_code)) {
                // Файл удалён (очистка wa-data) — восстанавливаем из БД
                $css_manager->saveFile($storefront_code, $custom_css);
                shopPrefillPluginLog::info('CSS file restored from DB on editor open', [
                    'storefront_code' => $storefront_code,
                ]);
            } else {
                shopPrefillPluginLog::debug('CSS editor opened: custom CSS loaded', [
                    'storefront_code' => $storefront_code,
                    'size'            => strlen($custom_css),
                ]);
            }
            $current = $custom_css;
        } else {
            shopPrefillPluginLog::debug('CSS editor opened: no custom CSS, showing default', [
                'storefront_code' => $storefront_code,
            ]);
            $current = $default;
        }

        $this->response = [
            'current_css' => $current,
            'default_css' => $default,
            'is_custom'   => $custom_css !== '',
        ];
    }
}
