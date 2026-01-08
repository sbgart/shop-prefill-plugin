<?php

class shopPrefillPluginFillParamsProvider
{
    private shopPrefillPluginOrderProvider $order_provider;
    private shopPrefillPluginUserProvider $user_provider;
    private shopPrefillPluginFillParamsStorage $fill_params_storage;
    private shopPrefillPluginLocationProvider $location_provider;
    private waResponse $response;

    private ?shopPrefillPluginFillParamsCollection $fill_params_collection = null;

    public function __construct(
        shopPrefillPluginOrderProvider $order_provider,
        shopPrefillPluginUserProvider $user_provider,
        shopPrefillPluginFillParamsStorage $fill_params_storage,
        shopPrefillPluginLocationProvider $location_provider,
        waResponse $response
    ) {
        $this->order_provider = $order_provider;
        $this->user_provider = $user_provider;
        $this->fill_params_storage = $fill_params_storage;
        $this->location_provider = $location_provider;
        $this->response = $response;
    }

    private function getOrderProvider(): shopPrefillPluginOrderProvider
    {
        return $this->order_provider;
    }

    private function getUserProvider(): shopPrefillPluginUserProvider
    {
        return $this->user_provider;
    }

    /**
     * @return shopPrefillPluginFillParamsStorage|null
     */
    private function getFillParamsStorage(): ?shopPrefillPluginFillParamsStorage
    {
        return $this->fill_params_storage;
    }

    private function getLocationProvider(): shopPrefillPluginLocationProvider
    {
        return $this->location_provider;
    }

    private function getResponse(): waResponse
    {
        return $this->response;
    }

    public function getFillParams(?int $fill_params_id = null): shopPrefillPluginFillParams
    {
        $fill_params = new shopPrefillPluginFillParams();

        $stored_fill_params = $this->getFillParamsStorage()->getStoredFillParams();

        // Если ид новых параметров не указан, то возвращаем кэшированные данные.
        if (!$fill_params_id && $stored_fill_params) {
            return $stored_fill_params;
        }

        // Формируем новые параметры предзаполнения
        $fill_params_array = $this->getFillParamsCollection()->get();
        // Если данные для предзаполнения в наличии.
        if ($fill_params_array) {
            $last_fill_params = end($fill_params_array);
            if ($fill_params_id && isset($fill_params_array[$fill_params_id])) {
                $fill_params = $fill_params_array[$fill_params_id];
                $fill_params->mergePaymentParams($last_fill_params);
            } else {
                $fill_params = $last_fill_params;
            }
        }

        // Подмешиваем параметры оплаты из кэша, если он ранее был сохранен.
        if ($stored_fill_params) {
            $fill_params->mergePaymentParams($stored_fill_params);
        }

        // Сохраняем в кэш.
        $this->getFillParamsStorage()->storeFillParams($fill_params);

        return $fill_params;
    }

    public function getFillParamsCollection(): shopPrefillPluginFillParamsCollection
    {
        if ($this->fill_params_collection) {
            return $this->fill_params_collection;
        }

        $this->fill_params_collection = new shopPrefillPluginFillParamsCollection();

        if (!$this->getUserProvider()->isAuth()) {
            return $this->fill_params_collection;
        }

        //Получаем уникальные параметры по префиксу "shipping_" из заказов пользователя.
        $orders_params = $this->getOrderProvider()->getUserOrdersParams($this->getUserProvider()->getId());
        $unique_orders_params = shopPrefillPluginFillParamsHelper::removeDuplicateSubarrays(
            $orders_params,
            "shipping_"
        );

        foreach ($unique_orders_params as $order_id => $order_params) {
            $fill_params = $this->getFillParamsByOrderParams($order_params, $order_id);
            $this->fill_params_collection->add($fill_params);
        }

        return $this->fill_params_collection;
    }

    public function getFillParamsByCheckoutParams(array $checkout_params): shopPrefillPluginFillParams
    {
        $fill_params = new shopPrefillPluginFillParams();

        // Получаем данные о регионе.
        $region_params = $checkout_params['order']['region'] ?? [];
        if ($region_params) {
            if (isset($region_params['country'])) {
                $fill_params->setCountry($region_params['country']);
            }

            if (isset($region_params['region'])) {
                $fill_params->setRegion($region_params['region']);
            }

            if (isset($region_params['city'])) {
                $fill_params->setCity($region_params['city']);
            }

            if (isset($region_params['zip'])) {
                $fill_params->setZip($region_params['zip']);
            }
        }


        // Получаем данные о доставке.
        $shipping_params = $checkout_params['order']['shipping'] ?? [];
        if ($shipping_params) {
            if (isset($shipping_params['type_id'])) {
                $fill_params->setShippingTypeId($shipping_params['type_id']);
            }

            if (isset($shipping_params['variant_id'])) {
                $fill_params->setShippingVariantId($shipping_params['variant_id']);
            }
        }


        // Получаем данные о деталях доставке.
        $shipping_details_params = $checkout_params['order']['details'] ?? [];
        if ($shipping_details_params) {
            if (isset($shipping_details_params['shipping_address']['street'])) {
                $fill_params->setStreet($shipping_details_params['shipping_address']['street']);
            }
        }

        // Получаем данные об оплате.
        $payment_params = $checkout_params['order']['payment'] ?? [];
        if ($payment_params) {
            if (isset($payment_params['id'])) {
                $fill_params->setPaymentId($payment_params['id']);
            }

            if (isset($payment_params['custom'])) {
                $fill_params->setPaymentCustom($payment_params['custom']);
            }
        }

        // Получаем данные о подтверждении.
        $confirm_params = $checkout_params['order']['payment'] ?? [];
        if (isset($confirm_params['comment'])) {
            $fill_params->setComment($confirm_params['comment']);
        }

        return $fill_params;
    }

    public function getFillParamsByOrderParams(array $order_params, int $id = null): shopPrefillPluginFillParams
    {
        $fill_params = new shopPrefillPluginFillParams();

        if ($id) {
            $fill_params->setId($id);
        }

        if (isset($order_params['shipping_address.country'])) {
            $fill_params->setCountry($order_params['shipping_address.country']);

            $country_name = $this->getLocationProvider()->getCountryName($order_params['shipping_address.country']);
            $fill_params->setCountryName($country_name);
        }

        if (isset($order_params['shipping_address.region'])) {
            $fill_params->setRegion($order_params['shipping_address.region']);

            $region_name = $this->getLocationProvider()->getRegionName(
                $order_params['shipping_address.country'],
                $order_params['shipping_address.region']
            );
            $fill_params->setRegionName($region_name);
        }

        if (isset($order_params['shipping_address.city'])) {
            $fill_params->setCity($order_params['shipping_address.city']);
        }

        if (isset($order_params['shipping_address.zip'])) {
            $fill_params->setZip($order_params['shipping_address.zip']);
        }

        if (isset($order_params['shipping_address.street'])) {
            $fill_params->setStreet($order_params['shipping_address.street']);
        }

        if (isset($order_params['shipping_id'])) {
            $fill_params->setShippingId((int)$order_params['shipping_id']);
        }
        if (isset($order_params['shipping_rate_id'])) {
            $fill_params->setShippingRateId($order_params['shipping_rate_id']);
        }
        if (isset($order_params['shipping_type_id'])) {
            $fill_params->setShippingTypeId((int)$order_params['shipping_type_id']);
        }

        if (isset($order_params['shipping_name'])) {
            $fill_params->setShippingName($order_params['shipping_name']);
        }

        $shipping_params = shopPrefillPluginFillParamsHelper::filteredOrderParams($order_params, 'shipping_params_');
        if (!empty($shipping_params)) {
            $fill_params->setShippingCustom($shipping_params);
        }

        $payment_params = shopPrefillPluginFillParamsHelper::filteredOrderParams($order_params, 'payment_params_');
        if (!empty($payment_params)) {
            $fill_params->setPaymentCustom($payment_params);
        }
        if (isset($order_params['payment_id'])) {
            $fill_params->setPaymentId((int)$order_params['payment_id']);
        }

        if (isset($order_params['comment'])) {
            $fill_params->setComment($order_params['comment']);
        }

        return $fill_params;
    }

}