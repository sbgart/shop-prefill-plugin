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

        return self::$shipping_methods_cache = self::checkInstancePlugins($plugins, $instances);
    }

    /**
     * @throws waException
     */
    public static function getSortedShippingMethods(array $criteria = []): array
    {
        $shippings = self::getShippingMethods();

        foreach ($shippings as $id => &$shipping) {
            if (array_key_exists($id, $criteria) && array_key_exists("sort", $criteria[$id])) {
                $shipping["sort"] = $criteria[$id]["sort"];
            }
        }

        uasort($shippings, function ($a, $b) {
            return ($a['sort'] - $b['sort']);
        });

        return $shippings;
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

        return self::$payment_methods_cache = self::checkInstancePlugins($plugins, $instances);
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
