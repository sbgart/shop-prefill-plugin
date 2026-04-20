<?php

class shopPrefillPluginPluginsProvider
{

    /**
     * @throws waException
     */
    private static ?array $shipping_methods_cache = null;
    private static ?array $payment_methods_cache = null;

    /**
     * @throws waException
     */
    public static function getShippingMethods(): array
    {
        if (self::$shipping_methods_cache !== null) {
            return self::$shipping_methods_cache;
        }

        $model = new shopPluginModel();
        $plugins = shopShipping::getList();
        $instances = $model->listPlugins(shopPluginModel::TYPE_SHIPPING);
        $instances = self::checkInstancePlugins($plugins, $instances);

        return self::$shipping_methods_cache = self::enrichZenMethodRows($instances, $plugins, 'shipping');
    }

    /**
     * @throws waException
     */
    public static function getPaymentMethods(): array
    {
        if (self::$payment_methods_cache !== null) {
            return self::$payment_methods_cache;
        }

        $model = new shopPluginModel();
        $plugins = shopPayment::getList();
        $instances = $model->listPlugins(shopPluginModel::TYPE_PAYMENT);
        $instances = self::checkInstancePlugins($plugins, $instances);

        return self::$payment_methods_cache = self::enrichZenMethodRows($instances, $plugins, 'payment');
    }

    /**
     * Добавляет поля для UI настроек Zen: заголовок плагина, URL иконки/логотипа.
     *
     * @param array<string, array<string, mixed>> $instances
     * @param array<string, array<string, mixed>> $plugins   shopShipping::getList() / shopPayment::getList()
     * @param string                               $kind      shipping|payment — тип для waSystemPlugin::info()
     * @return array<string, array<string, mixed>>
     */
    private static function enrichZenMethodRows(array $instances, array $plugins, string $kind): array
    {
        foreach ($instances as $id => &$row) {
            $pid = isset($row['plugin']) ? (string) $row['plugin'] : '';
            $info = ($pid !== '' && isset($plugins[$pid])) ? $plugins[$pid] : [];
            $meta = ($pid !== '' && ($resolved = waSystemPlugin::info($pid, [], $kind)) && is_array($resolved))
                ? $resolved
                : [];

            $title = '';
            if (!empty($meta['name']) && is_string($meta['name'])) {
                $title = $meta['name'];
            } elseif (!empty($info['name']) && is_string($info['name'])) {
                $title = $info['name'];
            } elseif ($pid !== '') {
                $title = $pid;
            }
            $row['prefill_plugin_title'] = $title;

            $icon = self::pluginIconUrlFromPluginInfo($meta);
            if ($icon === '') {
                $icon = self::pluginIconUrlFromPluginInfo($info);
            }
            $row['prefill_plugin_icon'] = $icon;
        }
        unset($row);

        return $instances;
    }

    /**
     * URL логотипа/иконки из массива плагина (как в waSystemPlugin::info() / shopShipping::getList()).
     *
     * @param array<string, mixed> $data
     */
    private static function pluginIconUrlFromPluginInfo(array $data): string
    {
        if (!empty($data['logo']) && is_string($data['logo'])) {
            return $data['logo'];
        }
        if (!empty($data['icon'])) {
            if (is_array($data['icon'])) {
                foreach ([48, 32, 24, 16] as $size) {
                    if (!empty($data['icon'][$size]) && is_string($data['icon'][$size])) {
                        return $data['icon'][$size];
                    }
                }
            } elseif (is_string($data['icon'])) {
                return $data['icon'];
            }
        }
        if (!empty($data['img']) && is_string($data['img'])) {
            return $data['img'];
        }

        return '';
    }

    private static function checkInstancePlugins(array $plugins, array $instances): array
    {
        foreach ($instances as $key => $instance) {
            // Удаляем инстанс, если плагин физически не установлен (нет файлов на диске)
            if (!isset($plugins[$instance['plugin']])) {
                unset($instances[$key]);
            }
        }

        return $instances;
    }
}
