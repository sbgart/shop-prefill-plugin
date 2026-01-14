<?php

class shopPrefillPluginCheckout
{
    /**
     * @throws SmartyException
     * @throws waException
     */
    public static function addShortShippingInfoSection(array &$checkout_params): void
    {
        $region_html = $checkout_params['result']['region']['html'] ?? null;
        if (! $region_html) {
            return;
        }

        $short_shipping_info_section                 = shopPrefillPluginViewProvider::render('/checkout/ShortShippingInfoSection');
        $checkout_params['result']['region']['html'] = $short_shipping_info_section . $region_html;
    }

    public static function addParamsChoiceLink(array &$checkout_params): string
    {
        // Манипулируем HTML секции региона через vars
        if (isset($checkout_params['vars']['region']['html'])) {
            $test_html = '<div style="background: yellow; padding: 20px; margin: 10px; border: 2px solid orange;">
                <strong>🎉 TEST IN REGION SECTION!</strong>
                <p>Вставлено в секцию региона через хук checkout_render_shipping</p>
            </div>';

            // Добавляем HTML в конец секции региона
            $checkout_params['vars']['region']['html'] .= $test_html;
        }

        // Возвращаем пустую строку, чтобы ничего не добавлялось в секцию shipping
        return '';
    }

}
