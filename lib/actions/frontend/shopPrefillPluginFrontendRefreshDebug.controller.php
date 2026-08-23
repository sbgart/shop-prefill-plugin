<?php

/**
 * Контроллер для обновления дебаг-панели через AJAX
 */
class shopPrefillPluginFrontendRefreshDebugController extends waJsonController
{
    /**
     * @return void
     */
    public function execute()
    {
        try {
            $plugin = shopPrefillPlugin::getInstance();

            if (! $plugin->isDebugPanelEnabled()) {
                $this->errors = ['error' => 'Access denied'];
                return;
            }

            // Получаем настройки витрины
            $storefront_settings = $plugin->getEffectiveStorefrontSettings();
            $plugin_enabled = !empty($storefront_settings['active']);

            // Получаем параметры предзаполнения и состояние хранилища
            $debug_data = shopPrefillPluginDebug::collectDebugData($plugin);
            $fill_params_data = $debug_data['fill_params_data'];
            $fill_params_meta = $debug_data['fill_params_meta'];
            $checkout_params = $debug_data['current_storage'];

            $this->response = [
                'status' => 'ok',
                'plugin_enabled' => $plugin_enabled,
                'zen_enabled' => !empty($storefront_settings['zen']['active']),
                'fill_params' => $fill_params_data,
                'fill_params_meta' => $fill_params_meta,
                'checkout_params' => $checkout_params,
                'timestamp' => date('H:i:s'),
                'errors' => [],
            ];

            // Рендерим HTML шаблоны
            $view = wa()->getView();
            $view->assign([
                'plugin_enabled' => $plugin_enabled,
                'zen_enabled' => !empty($storefront_settings['zen']['active']),
                'fill_params' => $fill_params_data,
                'fill_params_meta' => $fill_params_meta,
                'current_storage' => $checkout_params,
                'show_validation' => waRequest::cookie('wa_prefill_debug_show_validation', 0),
            ]);

            $template_path = shopPrefillPlugin::getPluginPath() . '/templates/debug/';

            $this->response['html_status'] = $view->fetch('file:' . $template_path . 'DebugStatusPanel.html');
            $this->response['html_storage'] = $view->fetch('file:' . $template_path . 'DebugStorageDetails.html');
            $this->response['html_params'] = $view->fetch('file:' . $template_path . 'DebugFillParams.html');
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Failed refreshing debug panel data in shopPrefillPluginFrontendRefreshDebugController', [
                'message' => $e->getMessage()
            ]);
            $this->errors = ['error' => $e->getMessage()];
        }
    }
}
