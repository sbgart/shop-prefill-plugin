<?php

/**
 * Контроллер для переключения статуса предзаполнения (toggle)
 * Используется для дебага через меню Actions в debug панели
 */
class shopPrefillPluginFrontendTogglePrefillController extends waJsonController
{
    public function execute()
    {
        // Устанавливаем правильный заголовок
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        try {
            // Получаем экземпляр плагина
            $plugin = shopPrefillPlugin::getInstance();

            // Получаем текущие настройки витрины
            $storefront = $plugin->getStorefrontProvider()->getCurrentStorefront();
            $settings = $storefront->getSettings();

            // Переключаем состояние (toggle)
            $current_state = !empty($settings['prefill']['active']);
            $new_state = !$current_state;

            // Обновляем настройку
            $settings['prefill']['active'] = $new_state;

            // Сохраняем настройки
            $storefront->saveSettings($settings);

            // Очищаем статический кэш настроек витрины в плагине
            shopPrefillPlugin::clearStorefrontSettingsCache();

            $this->response = [
                'status' => 'ok',
                'enabled' => $new_state,
                'message' => $new_state ? 'Prefill enabled' : 'Prefill disabled'
            ];
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Failed toggling prefill state in shopPrefillPluginFrontendTogglePrefillController', [
                'message' => $e->getMessage()
            ]);
            $this->errors = [
                'error' => $e->getMessage()
            ];
        }
    }
}
