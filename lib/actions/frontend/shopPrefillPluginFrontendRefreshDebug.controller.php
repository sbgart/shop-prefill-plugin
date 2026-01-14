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

            // Получаем настройки витрины
            $storefront_settings = $plugin->getStorefrontSettings();
            $plugin_enabled      = ! empty($storefront_settings['prefill']['active']);

            // Получаем параметры предзаполнения
            $fill_params_data = [];
            $fill_params_meta = [
                'user_authorized' => false,
                'user_id'         => null,
                'contact_id'      => null,
                'guest_hash'      => null,
                'orders_count'    => 0,
                'source'          => 'empty',
                'source_order_id' => null,
            ];

            // Проверяем авторизацию
            $user_provider                       = $plugin->getUserProvider();
            $guest_hash_storage                  = $plugin->getGuestHashStorage();
            $fill_params_meta['user_authorized'] = $user_provider->isAuth();

            if ($fill_params_meta['user_authorized']) {
                // Авторизованный пользователь
                $fill_params_meta['user_id']    = $user_provider->getId();
                $fill_params_meta['contact_id'] = $user_provider->getId();

                // Получаем количество заказов
                $order_provider                   = $plugin->getOrderProvider();
                $orders_ids                       = $order_provider->getUserOrdersId($fill_params_meta['user_id']);
                $fill_params_meta['orders_count'] = count($orders_ids ?: []);
            } else {
                // Гость: показываем хеш
                $guest_hash                     = $guest_hash_storage->getGuestHash();
                $fill_params_meta['guest_hash'] = $guest_hash ? substr($guest_hash, 0, 16) . '...' : null;

                // Получаем количество заказов гостя
                if ($guest_hash) {
                    $order_provider                   = $plugin->getOrderProvider();
                    $orders_ids                       = $order_provider->getAllOrderIdsByGuestHash($guest_hash);
                    $fill_params_meta['orders_count'] = count($orders_ids);
                }
            }

            // Получаем параметры предзаполнения из БД
            $fill_params      = $plugin->getFillParamsProvider()->getFillParams();
            $fill_params_data = $fill_params->toArray();

            // Определяем источник данных
            $order_id = $fill_params->getId();
            if ($order_id) {
                $fill_params_meta['source']          = 'order';
                $fill_params_meta['source_order_id'] = $order_id;
            } elseif ($fill_params_meta['orders_count'] > 0) {
                $fill_params_meta['source'] = 'orders (no data)';
            } else {
                $fill_params_meta['source'] = 'empty (no orders)';
            }

            // Получаем текущее состояние хранилища checkout
            $session_storage = $plugin->getSessionStorageProvider();
            $checkout_params = $session_storage->getCheckoutParams() ?: [];

            $this->response = [
                'status'           => 'ok',
                'plugin_enabled'   => $plugin_enabled,
                'fill_params'      => $fill_params_data,
                'fill_params_meta' => $fill_params_meta,
                'checkout_params'  => $checkout_params,
                'timestamp'        => date('H:i:s'),
            ];
        } catch (Exception $e) {
            $this->errors = ['error' => $e->getMessage()];
        }
    }
}
