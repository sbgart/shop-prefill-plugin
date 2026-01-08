<?php

class shopPrefillPluginViewProvider
{
    /**
     * @throws waException
     * @throws SmartyException
     */
    public static function render(string $path, array $params = []): string
    {
        $view = wa()->getView();

        $params['plugin_url'] = shopPrefillPlugin::getStaticUrl();

        $view->assign($params);

        $view_path = shopPrefillPlugin::getPluginPath() . '/templates/' . $path;

        return $view->fetch($view_path . '.html');
    }


    public static function createCssVariablesString($params): string
    {
        $css_variables = '';
        foreach ($params as $key => $value) {
            $css_variables .= "--{$key}: {$value};\n";
        }

        return "
        :root {
            {$css_variables}
        }";
    }

    /**
     * @throws waException
     * @throws SmartyException
     */
    public static function getFormattedMessage(string $template, array $params): string
    {
        $message = wa()->getView();
        $message->assign($params);

        return $message->fetch('string:' . $template);
    }

    /**
     * @throws waException
     * @throws waDbException
     */
    public static function getFormattedPrice(int $amount, bool $html = true): string
    {
        $shop_currency = wa()->getSetting('currency', 'RUB', 'shop');
        if ($html) {
            return '<span style="white-space:nowrap!important;">' . shop_currency_html($amount, $shop_currency)
                . '</span>';
        } else {
            return shop_currency($amount, $shop_currency);
        }
    }

}
