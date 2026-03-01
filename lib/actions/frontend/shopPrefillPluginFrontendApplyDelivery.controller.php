<?php

class shopPrefillPluginFrontendApplyDeliveryController extends waJsonController
{
    /**
     * @throws waException
     */
    public function execute()
    {
        $order_id = waRequest::post('order_id', null, waRequest::TYPE_INT);

        if (!$order_id) {
            $this->errors = 'Missing order_id';
            return;
        }

        $instance = shopPrefillPlugin::getInstance();

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
