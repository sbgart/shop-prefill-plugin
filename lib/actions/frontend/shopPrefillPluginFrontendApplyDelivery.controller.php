<?php

class shopPrefillPluginFrontendApplyDeliveryController extends waJsonController
{
    /**
     * @throws waException
     */
    public function execute()
    {
        $instance = shopPrefillPlugin::getInstance();
        $settings = $instance->getEffectiveStorefrontSettings();

        if (!$settings['active']) {
            $this->errors = 'Plugin is inactive for this storefront';
            return;
        }

        // order_id относится к истории «Мои варианты». Гостевая cookie разрешает
        // только автопредзаполнение последнего заказа и не открывает всю историю.
        if (!$instance->getUserProvider()->isAuth()
            || empty($settings['prefill']['my_delivery_variants'])
        ) {
            wa()->getResponse()->setStatus(403);
            $this->errors = 'Access denied';
            return;
        }

        $order_id = waRequest::post('order_id', null, waRequest::TYPE_INT);

        if (!$order_id) {
            $this->errors = 'Missing order_id';
            return;
        }

        try {
            $fill_params = $instance->getFillParamsProvider()->getFillParams($order_id);
            $instance->getSessionStorageProvider()->applyDeliveryAddress($fill_params);

            $this->response = ['status' => 'ok'];
        } catch (Exception $e) {
            shopPrefillPluginLog::error('ApplyDelivery failed', [
                'order_id' => $order_id,
                'message' => $e->getMessage(),
            ]);
            $this->errors = $e->getMessage();
        }
    }
}
