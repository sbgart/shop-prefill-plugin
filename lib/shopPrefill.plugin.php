<?php

class shopPrefillPlugin extends shopPlugin
{
    public const APP_ID = "shop";
    public const PLUGIN_ID = "prefill";

    public static shopPrefillPlugin $instance;

    private static ?bool $active = null;
    private static ?bool $enable_install = null;

    private static ?array $installed_shop_plugins = null;
    private static ?string $plugin_path = null;
    private static ?array $storefront_settings = null;
    private static bool $frontend_assets_inited = false;

    private ?shopPrefillPluginFillParams $prefill_params = null;
    private ?shopPrefillPluginSettingProvider $setting_provider = null;
    private ?shopPrefillPluginStorefrontProvider $storefront_provider = null;
    private ?shopPrefillPluginPluginsProvider $plugins_provider = null;
    private ?shopPrefillPluginUserProvider $user_provider = null;
    private ?shopPrefillPluginLocationProvider $location_provider = null;

    private ?shopOrderModel $shop_order_model = null;
    private ?shopOrderParamsModel $shop_order_params_model = null;

    private ?shopPrefillPluginOrderProvider $order_provider = null;

    private ?shopPrefillPluginSessionStorageProvider $session_storage_provider = null;

    private ?shopPrefillPluginFillParamsProvider $fill_params_provider = null;

    private ?shopPrefillPluginFillParamsStorage $fill_params_storage = null;

    public function __construct($info)
    {
        parent::__construct($info);

        self::$instance ??= $this;
    }

    /**
     * @throws waException
     */
    public static function getInstance(): shopPrefillPlugin
    {
        return self::$instance ?? wa(self::APP_ID)->getPlugin(self::PLUGIN_ID);
    }

    public static function getInstalledShopPlugins(): array
    {
        return $installed_shop_plugins ??= wa('shop')->getConfig()->getPlugins();
    }


    public static function enableInstall($plugin_id): bool
    {
        return isset(self::getInstalledShopPlugins()[$plugin_id]);
    }

    /**
     * @throws waException
     * @throws waDbException
     */
    public function isActive(): bool
    {
        return self::$active ??= (self::enableInstall(self::PLUGIN_ID))
            && ($this->getSettingProvider()->getSettings()['active'] === true);
    }

    public function isDebug(): bool
    {
        return waSystemConfig::isDebug();
    }

    /**
     * @throws waException
     */
    public static function getPluginPath(): string
    {
        return self::$plugin_path ??= wa()->getAppPath('plugins/' . self::PLUGIN_ID, self::APP_ID);
    }

    /**
     * Returns config from plugin's config dir
     *
     * @param  string  $name  - File name without extension
     *
     * @return array
     * @throws waException
     */
    public static function getConfig(string $name): array
    {
        $config_file = self::getPluginPath() . '/lib/config/' . $name . '.php';

        if (!file_exists($config_file)) {
            return [];
        }

        return include($config_file);
    }

    public function getSettingProvider(): shopPrefillPluginSettingProvider
    {
        return $this->setting_provider ??= new shopPrefillPluginSettingProvider();
    }

    public function getStorefrontProvider(): shopPrefillPluginStorefrontProvider
    {
        return $this->storefront_provider ??= new shopPrefillPluginStorefrontProvider();
    }

    /**
     * @throws waException
     * @throws waDbException
     */
    public function getStorefrontSettings(): array
    {
        return self::$storefront_settings ??= self::getStorefrontProvider()->getCurrentStorefront()->getSettings();
    }

    public function getPluginsProvider(): shopPrefillPluginPluginsProvider
    {
        return $this->plugins_provider ??= new shopPrefillPluginPluginsProvider();
    }

    /**
     * @throws waException
     */
    public function getUserProvider(): shopPrefillPluginUserProvider
    {
        return $this->user_provider ??= new shopPrefillPluginUserProvider(
            wa()->getUser()
        );
    }

    public function getLocationProvider(): shopPrefillPluginLocationProvider
    {
        return $this->location_provider ??= new shopPrefillPluginLocationProvider(
            new waCountryModel(),
            new waRegionModel()
        );
    }

    /**
     * @throws waException
     */
    public function getFillParamsProvider(): shopPrefillPluginFillParamsProvider
    {
        return $this->fill_params_provider ??= new shopPrefillPluginFillParamsProvider(
            $this->getOrderProvider(),
            $this->getUserProvider(),
            $this->getFillParamsStorage(),
            $this->getLocationProvider(),
            wa()->getResponse()
        );
    }

    /**
     * @throws waException
     */
    public function getFillParamsStorage(): ?shopPrefillPluginFillParamsStorage
    {
        return $this->fill_params_storage ??= new shopPrefillPluginFillParamsStorage(
            $this->getUserProvider(),
            wa()->getResponse()
        );
    }

    public function getOrderProvider(): shopPrefillPluginOrderProvider
    {
        return $this->orders_provider ??= new shopPrefillPluginOrderProvider(
            new shopOrderModel(),
            new shopOrderParamsModel()
        );
    }

    /**
     * @throws waException
     * @throws waDbException
     */
    public function getSessionStorageProvider(): shopPrefillPluginSessionStorageProvider
    {
        return $this->session_storage_provider ??= new shopPrefillPluginSessionStorageProvider(
            $this->getStorefrontSettings()['prefill']['disable'] ?? []
        );
    }

    /**
     * @throws waException
     */
    public static function getStaticUrl($url = '', $absolute = false): string
    {
        return wa('shop')->getAppStaticUrl(self::APP_ID, $absolute) . 'plugins/'
            . self::PLUGIN_ID . $url;
    }

    /**
     * @throws waException
     */
    private function frontendAssetsInit(array $css_variables = [], array $js_params = []): void
    {
        if (!self::$frontend_assets_inited) {
            $is_debug = $this->isDebug();
            $this->addCss('css/frontend.' . (!$is_debug ? 'min.' : '') . 'css');
            $this->addJs('js/frontend.' . (!$is_debug ? 'min.' : '') . 'js?');

            if (!empty($css_variables)) {
                $css_variables_filename = $this->generateCssVariablesFile($css_variables);
                wa()->getResponse()->addCss(
                    substr(wa()->getDataUrl('plugins/' . self::PLUGIN_ID . '/css/', true, 'shop'), 1)
                    . $css_variables_filename
                );
            }

            $js_initializer_filename = $this->generateJSInitializerFile($js_params);
            wa()->getResponse()->addJs(
                substr(wa()->getDataUrl('plugins/' . self::PLUGIN_ID . '/js/', true, 'shop'), 1)
                . $js_initializer_filename
            );

            self::$frontend_assets_inited = true;
        }
    }

    /**
     * @throws waException
     */
    private function generateCssVariablesFile(array $css_variables): string
    {
        // Generate css variables file from the storefront settings and add it
        //TODO: Возможно стоит переделать с md5 на дату обновления настроек витрины, тем самым если файла с датой настроек не будет, то сгенерировать новый файл.
        $css_variables_map = shopDolonPluginViewProvider::createCssVariablesString($css_variables);
        $css_variables_filename = 'variables_' . md5($css_variables_map) . '.css';
        $css_public_dir = wa()->getDataPath('plugins/' . self::PLUGIN_ID . '/css/', true, 'shop');

        if (!file_exists($css_public_dir . $css_variables_filename)) {
            file_put_contents($css_public_dir . $css_variables_filename, $css_variables_map);
        }

        return $css_variables_filename;
    }

    /**
     * @throws waException
     */
    private function generateJSInitializerFile(array $params): string
    {
        $json_params = json_encode(
            $params,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES

        );

        $inline_script = <<<JS
document.addEventListener('DOMContentLoaded', function() {
    let params = $json_params;
    window.prefill = new PrefillFrontendController(params);
});
JS;
        $js_file_name = md5($inline_script) . '.js';
        $js_public_dir = wa()->getDataPath('plugins/' . self::PLUGIN_ID . '/js/', true, 'shop');
        if (!file_exists($js_public_dir . $js_file_name)) {
            file_put_contents($js_public_dir . $js_file_name, $inline_script);
        }

        return $js_file_name;
    }


    /**
     * Хук срабатывает на странице оформления заказа в корзине.
     *
     * @throws waException
     * @throws waDbException
     */
    public function frontendOrder($params)
    {
        if (!$this->isActive()) {
            return;
        }

        $storefront_settings = $this->getStorefrontSettings();

        if ($storefront_settings['active'] !== true) {
            return;
        }

        if ($storefront_settings['prefill']['active']) {
            $this->getSessionStorageProvider()->fillCheckoutParams(
                $this->getFillParamsProvider()->getFillParams()
            );
        }
    }

    /**
     * Хук срабатывает на всех страницах магазина.
     * Предзаполняем параметры сразу при входе на сайт.
     *
     * @throws waException
     * @throws waDbException
     */
    public function frontendHead($params)
    {
        if (!$this->isActive()) {
            return;
        }
        //wa()->getStorage()->set('shop/checkout', '');

        $storefront_settings = $this->getStorefrontSettings();

        if (!$storefront_settings['active']) {
            return;
        }

        // Создаем или обновляем куки авторизации пользователя.
        if ($storefront_settings['remember_me']['active'] && $this->getUserProvider()->isAuth()) {
            $this->getUserProvider()->rememberMe($storefront_settings['remember_me']['expires']);
        }

        // Предзаполнение включено, заполняем параметры корзины при входе на сайт
        if ($storefront_settings['prefill']['active']) {
            if ($storefront_settings['prefill']['on_entry']) {
                // $this->getSessionStorageProvider()->preFillCheckoutParams(
                //     $this->getFillParamsProvider()->getFillParams()
                //  );
            }
        }

        // Инициализируем стили и скрипты.
        $css_variables = [
            'prefill-accent-color' => $storefront_settings['styles']['accent_color'],
        ];

        $js_params = [
            'pluginID' => $this::PLUGIN_ID,
            'isDebug'  => $this->isDebug(),
        ];

        self::frontendAssetsInit($css_variables, $js_params);
    }

    /**
     * Хук срабатывает перед формированием HTML-кода шага оформления заказа «выбор способа доставки» на странице оформления заказа в корзине.
     * Добавляем ссылку на вызов диалога выбора способа доставки.
     *
     * @throws waException
     * @throws SmartyException
     */
    public function checkoutRenderShipping(&$params)
    {
        // Check if plugin is active
        if (!$this->isActive()) {
            return;
        }

        // $tt = $params['data']['order']->getShippingMethods(); // Возвращает все методы доставки
        // $ee = $params['data']['order']->getShippingPluginInfo(); // возвращает всю ту же информацию что мы получаем через вызов плагина доставки

        // Предзаполнение параметров.
        $this->getFillParamsStorage()->storeFillParams(
            $this->getFillParamsProvider()->getFillParamsByCheckoutParams(
                $this->getSessionStorageProvider()->getCheckoutParams()
            )
        );

        // Добавляем секцию краткой информации о доставке
        shopPrefillPluginCheckout::addParamsChoiceLink($params);
        $params["data"]["result"]["region"]["html"] = "rr";
    }

    /**
     * Хук срабатывает при создании заказа.
     * Сохраняем shipping_type_id в параметры заказа.
     *
     * @throws waException
     */
    public function orderActionCreate($data)
    {
        if (!$this->isActive()) {
            return;
        }

        // Сохраняем дополнительные параметры заказа.
        $checkout_params = $this->getSessionStorageProvider()->getCheckoutParams();

        // TODO: Ведь можно сделать что бы и не для зареганных юзеров сохранялись параметры

        if (isset($data['order_id'])) {
            $this->getOrderProvider()->storeShippingTypeId(
                (int)$data['order_id'],
                (int)$checkout_params['order']['shipping']['type_id']
            );
            $this->getOrderProvider()->storeComment(
                (int)$data['order_id'],
                $checkout_params['order']['confirm']['comment']
            );
        }
    }

    /**
     * @throws waException
     */
    public function saveSettings($settings = array())
    {
        if (isset($settings['storefront'])) {
            foreach ($settings['storefront'] as $storefront_code => $storefront_settings) {
                $this->getStorefrontProvider()->getStorefront($storefront_code)->saveSettings($storefront_settings);
            }
            unset($settings['storefront']);
        }

        $this->getSettingProvider()->saveSettings($settings);
    }

}