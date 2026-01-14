<?php

/**
 * Контроллер для переключения статуса предзаполнения
 * Используется для дебага через чекбокс в debug окне
 */
class shopPrefillPluginFrontendTogglePrefillController extends waJsonController
{
    public function execute()
    {
        // Устанавливаем правильный заголовок
        if (! headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        try {
            // Читаем JSON из тела запроса
            $input = file_get_contents('php://input');
            $data  = json_decode($input, true);

            // Получаем значение enabled из JSON или из POST
            $enabled = false;
            if (isset($data['enabled'])) {
                $enabled = (bool) $data['enabled'];
            } else {
                $enabled = (bool) waRequest::post('enabled', false);
            }

            // Получаем экземпляр плагина
            $plugin = shopPrefillPlugin::getInstance();

            // Получаем текущие настройки витрины
            $storefront = $plugin->getStorefrontProvider()->getCurrentStorefront();
            $settings   = $storefront->getSettings();

            // Обновляем настройку
            $settings['prefill']['active'] = $enabled;

            // Сохраняем настройки
            $storefront->saveSettings($settings);

            // Очищаем статический кэш настроек витрины в плагине
            shopPrefillPlugin::clearStorefrontSettingsCache();

            $this->response = [
                'status'  => 'ok',
                'enabled' => $enabled,
                'message' => $enabled ? 'Prefill enabled' : 'Prefill disabled'
            ];
        } catch (Exception $e) {
            $this->errors = [
                'error' => $e->getMessage()
            ];
        }
    }
}
