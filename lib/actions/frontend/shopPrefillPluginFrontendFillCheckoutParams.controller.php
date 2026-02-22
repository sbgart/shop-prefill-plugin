<?php

class shopPrefillPluginFrontendFillCheckoutParamsController extends waJsonController
{
    /**
     * @throws waException
     * @throws waDbException
     */
    public function execute()
    {
        $fill_params_id = waRequest::post('id', null);

        $instance = shopPrefillPlugin::getInstance();

        try {
            $fill_params = $instance->getFillParamsProvider()->getFillParams($fill_params_id);
            $instance->getSessionStorageProvider()->preFillCheckoutParams($fill_params);

            shopPrefillPluginLog::info('Manually applied checkout params via FillCheckoutParamsController', [
                'fill_params_id' => $fill_params_id
            ]);

            return json_encode(array('status' => 'success'));
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Failed manually applying checkout params', [
                'fill_params_id' => $fill_params_id,
                'message' => $e->getMessage()
            ]);
            return json_encode(array('status' => 'error', 'message' => $e->getMessage()));
        }
    }
}