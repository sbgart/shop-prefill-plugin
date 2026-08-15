<?php
/** @noinspection PhpPossiblePolymorphicInvocationInspection */

class shopPrefillPluginSettingsStorefrontAction extends waViewAction
{
    /**
     * @throws waException
     * @throws waDbException
     */
    public function execute()
    {
        $storefront_code = waRequest::post('code');

        $app_id = shopPrefillPlugin::APP_ID;
        $plugin_id = shopPrefillPlugin::PLUGIN_ID;

        $plugin = shopPrefillPlugin::getInstance();

        $shippingMethods = shopPrefillPluginPluginsProvider::getShippingMethods();
        $paymentMethods  = shopPrefillPluginPluginsProvider::getPaymentMethods();


        // Locale config
        waLocale::loadByDomain(['shop', 'prefill']);
        waSystem::pushActivePlugin('prefill', 'shop');

        // Витрину могли удалить или переименовать после загрузки списка в браузере
        $storefront = $plugin->getStorefrontProvider()->findStorefront($storefront_code);

        if ($storefront === null) {
            throw new waException(_wp('error.storefront_not_found'), 404);
        }

        $this->view->assign([
            'app_id'               => shopPrefillPlugin::APP_ID,
            'plugin_id'            => shopPrefillPlugin::PLUGIN_ID,
            'name_prefix'          => $app_id.'_'.$plugin_id.'[storefront]['.$storefront_code.']',
            'storefront_code'      => $storefront_code,
            'settings'             => $storefront->getSettings(),
            'global_settings'      => $plugin->getSettingProvider()->getSettings(),
            'shipping_methods'     => $shippingMethods,
            'payment_methods'      => $paymentMethods,
            'auth_dependencies'    => $this->getAuthDependencies($storefront),
        ]);
    }

    /**
     * Состояние внешних настроек, без которых продление авторизации не работает.
     *
     * Обе живут вне плагина, поэтому показываем их статус прямо в настройках —
     * иначе админ не поймёт, почему функция молчит.
     *
     * @return array{
     *     rememberme: bool|null,
     *     rememberme_url: string,
     *     order_without_auth: string,
     *     order_without_auth_url: string
     * }
     */
    private function getAuthDependencies(shopPrefillPluginStorefront $storefront): array
    {
        $domain = $storefront->getDomain();

        return [
            // null — глобальная витрина: конкретный домен не определён
            'rememberme'             => $this->isDomainRememberMeEnabled($domain),
            'rememberme_url'         => wa()->getAppUrl('site'),
            'order_without_auth'     => $this->getOrderWithoutAuthMode($storefront),
            'order_without_auth_url' => $this->getCheckoutSettingsUrl($storefront),
        ];
    }

    /**
     * Ссылка на раздел настроек чекаута этой витрины (hash-роут SPA магазина),
     * формат — как в SettingsCheckoutSidebar.html.
     */
    private function getCheckoutSettingsUrl(shopPrefillPluginStorefront $storefront): string
    {
        $url      = wa()->getAppUrl('shop').'?action=settings#/checkout2';
        $domain   = $storefront->getDomain();
        $route_id = $this->findRouteId($storefront);

        if ($domain === '' || $domain === '*' || $route_id === null) {
            return $url;
        }

        return $url.'&domain='.urlencode(waIdna::dec($domain)).'&route='.urlencode($route_id).'/';
    }

    /**
     * Идентификатор маршрута витрины.
     *
     * waRouting::getByApp() отдаёт его ключом массива, а не полем маршрута,
     * поэтому в самом объекте витрины его нет — ищем сопоставлением по URL.
     */
    private function findRouteId(shopPrefillPluginStorefront $storefront): ?string
    {
        $domain = $storefront->getDomain();
        if ($domain === '' || $domain === '*') {
            return null;
        }

        $routes = wa()->getRouting()->getByApp(shopPrefillPlugin::APP_ID, $domain);
        foreach ($routes as $route_id => $route) {
            if (ifset($route, 'url', null) === $storefront->getUrl()) {
                return (string) $route_id;
            }
        }

        return null;
    }

    /**
     * Тумблер «Запомнить меня» домена витрины (приложение «Сайт»).
     * Без него waAuth::_authByCookie() игнорирует cookie auth_token.
     */
    private function isDomainRememberMeEnabled(string $domain): ?bool
    {
        if ($domain === '' || $domain === '*') {
            return null;
        }

        try {
            $config = waDomainAuthConfig::factory($domain);
        } catch (Exception $e) {
            return null;
        }

        return $config ? $config->getRememberMe() : null;
    }

    /**
     * Режим обновления профилей покупателей из настроек чекаута магазина.
     *
     * В бэкенде текущего маршрута нет, поэтому витрину передаём явно:
     * shopCheckoutConfig проверяет её по route.checkout_storefront_id.
     */
    private function getOrderWithoutAuthMode(shopPrefillPluginStorefront $storefront): string
    {
        $checkout_storefront_id = $storefront->getRoute('checkout_storefront_id');

        if (!class_exists('shopCheckoutConfig') || empty($checkout_storefront_id)) {
            return '';
        }

        try {
            $config = new shopCheckoutConfig($checkout_storefront_id);
        } catch (Exception $e) {
            return '';
        }

        $mode = $config['confirmation']['order_without_auth'] ?? '';

        return is_string($mode) ? $mode : '';
    }

}
