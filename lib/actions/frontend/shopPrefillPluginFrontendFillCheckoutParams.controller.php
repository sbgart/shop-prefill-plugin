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

        $instance->getSessionStorageProvider()->fillCheckoutParams(
            $instance->getFillParamsProvider()->getFillParams($fill_params_id)
        );
        $tt = wa()->getStorage()->get('shop/checkout');
        $ttt = $tt;

        return json_encode(array('test' => 'test'));
    }
}