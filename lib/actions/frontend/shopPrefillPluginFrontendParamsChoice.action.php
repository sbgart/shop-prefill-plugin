<?php

class shopPrefillPluginFrontendParamsChoiceAction extends waViewAction
{

    /**
     * @throws waException
     */
    public function execute()
    {
        $instance = shopPrefillPlugin::getInstance();
        $settings = $instance->getEffectiveStorefrontSettings();

        if (!$settings['active']) {
            return;
        }

        // История адресов доступна только после авторизации: гостевая cookie нужна
        // для автопредзаполнения последнего заказа, но не является учётной записью.
        if (!$instance->getUserProvider()->isAuth()
            || empty($settings['prefill']['my_delivery_variants'])
        ) {
            throw new waRightsException('Access denied');
        }

        // Сколько карточек показывать — решает владелец магазина (настройка витрины)
        $limit = shopPrefillPluginFillParamsCollection::normalizeLimit(
            $settings['prefill']['my_delivery_variants_limit'] ?? null
        );

        $fill_params_collection = $instance->getFillParamsProvider()->getFillParamsCollection($limit);
        $fill_params_array      = [];
        $items                  = $fill_params_collection->get();

        // Гарантируем, что при лимите остаются самые свежие (с максимальным order_id)
        usort($items, static function (shopPrefillPluginFillParams $left, shopPrefillPluginFillParams $right): int {
            return (int) $right->getId() <=> (int) $left->getId();
        });

        // Страховка: коллекция уже собрана под лимит, но экшен не полагается на это
        $items = array_slice($items, 0, $limit);

        // Определяем текущий сценарий доставки для подсветки активной карточки
        $checkout_params = $instance->getSessionStorageProvider()->getCheckoutParams();
        $current         = $instance->getFillParamsProvider()->getFillParamsByCheckoutParams($checkout_params);

        foreach ($items as $item_obj) {
            $item_array               = $item_obj->toArray();
            $item_array['is_current'] = $item_obj->isSameDeliveryOption($current);
            $fill_params_array[]      = $item_array;
        }

        $this->view->assign([
            'app_id'            => shopPrefillPlugin::APP_ID,
            'plugin_id'         => shopPrefillPlugin::PLUGIN_ID,
            'plugin_url'        => shopPrefillPlugin::getStaticUrl(),
            'fill_params_array' => $fill_params_array,
        ]);
    }

}
