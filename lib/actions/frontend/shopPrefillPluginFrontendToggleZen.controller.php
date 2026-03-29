<?php

/**
 * Контроллер для переключения Zen Mode (toggle)
 * Используется для дебага через меню Actions в debug панели
 */
class shopPrefillPluginFrontendToggleZenController extends waJsonController
{
    public function execute()
    {
        if (! shopPrefillPlugin::getInstance()->isDebug() || ! wa()->getUser()->isAdmin('shop')) {
            $this->errors = 'Access denied';
            return;
        }

        try {
            // Получаем экземпляр плагина
            $plugin = shopPrefillPlugin::getInstance();

            // Получаем текущие настройки витрины
            $storefront = $plugin->getStorefrontProvider()->getCurrentStorefront();
            $settings = $storefront->getSettings();

            // Переключаем состояние (toggle)
            $current_state = !empty($settings['zen']['active']);
            $new_state = !$current_state;

            // Обновляем настройку zen.active
            $settings['zen']['active'] = $new_state;

            // Сохраняем настройки
            $storefront->saveSettings($settings);

            // Очищаем статический кэш настроек витрины в плагине
            shopPrefillPlugin::clearStorefrontSettingsCache();

            $this->response = [
                'status' => 'ok',
                'enabled' => $new_state,
                'message' => $new_state ? 'Zen Mode enabled' : 'Zen Mode disabled'
            ];
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Failed toggling Zen Mode state in shopPrefillPluginFrontendToggleZenController', [
                'message' => $e->getMessage()
            ]);
            $this->errors = [
                'error' => $e->getMessage()
            ];
        }
    }
}
