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
        if (!$region_html) {
            return;
        }

        $short_shipping_info_section = shopPrefillPluginViewProvider::render('/checkout/ShortShippingInfoSection');
        $checkout_params['result']['region']['html'] = $short_shipping_info_section . $region_html;
    }

    public static function addParamsChoiceLink(array &$checkout_params): void
    {
        $shipping_section_html = $checkout_params["data"]["result"]["region"]["html"] ?? null;

        if (!$shipping_section_html) {
            return;
        }

        libxml_use_internal_errors(true);

        $html = mb_convert_encoding($shipping_section_html, 'HTML-ENTITIES', 'UTF-8');
        $dom = new DOMDocument();
        $dom->encoding = 'UTF-8';
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        libxml_clear_errors();

        $region_block_header = $xpath->query(
            "//*[contains(@class, 'wa-section-header')]//*[contains(@class, 'wa-header')]"
        );

        $tt = $region_block_header[0]->nodeValue;

        if ($region_block_header->length > 0) {
            $region_block_header[0]->nodeValue = 'Test!';
        }

        $checkout_params["data"]["result"]["region"]["html"] = "ddd";//$dom->saveHTML();
    }

}
