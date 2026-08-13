<?php

class shopPrefillPluginFrontendFillCheckoutParamsController extends waJsonController
{
    /**
     * @throws waException
     * @throws waDbException
     */
    public function execute()
    {
        $instance = shopPrefillPlugin::getInstance();

        if (!$instance->getEffectiveStorefrontSettings()['active']) {
            $this->setError('Plugin is inactive for this storefront');
            return;
        }

        $fill_params_id = waRequest::post('id', null);

        try {
            $fill_params = $instance->getFillParamsProvider()->getFillParams($fill_params_id);
            $instance->getSessionStorageProvider()->preFillCheckoutParams($fill_params);

            shopPrefillPluginLog::info('Manually applied checkout params via FillCheckoutParamsController', [
                'fill_params_id' => $fill_params_id
            ]);

        } catch (Exception $e) {
            shopPrefillPluginLog::error('Failed manually applying checkout params', [
                'fill_params_id' => $fill_params_id,
                'message'        => $e->getMessage()
            ]);

            $this->setError($e->getMessage(), array(
                'fill_params_id' => $fill_params_id,
            ));
            return;
        }

        $this->response = array(
            'fill_params_id' => $fill_params_id,
        );
    }
}
