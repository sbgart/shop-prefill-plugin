<?php

class shopPrefillPluginPluginsProvider
{

    /**
     * @throws waException
     */
    public static function getShippingMethods(): array
    {
        $model = new shopPluginModel();
        $plugins = shopShipping::getList();
        $instances = $model->listPlugins(shopPluginModel::TYPE_SHIPPING);

        return self::checkInstancePlugins($plugins, $instances);
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
        $model = new shopPluginModel();
        $plugins = shopPayment::getList();
        $instances = $model->listPlugins(shopPluginModel::TYPE_PAYMENT);

        return self::checkInstancePlugins($plugins, $instances);
    }


    private static function checkInstancePlugins(array $plugins, array $instances): array
    {
        foreach ($instances as $key => $instance) {
            if (!isset($plugins[$instance['plugin']])) {
                unset($instance[$key]);
            }
        }

        return $instances;
    }
}
