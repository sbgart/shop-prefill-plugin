<?php

class shopPrefillPlugin extends shopPlugin
{
    public const APP_ID    = "shop";
    public const PLUGIN_ID = "prefill";

    public static shopPrefillPlugin $instance;

    private static ?bool $active         = null;
    private static ?bool $enable_install = null;

    private static ?array  $installed_shop_plugins = null;
    private static ?string $plugin_path            = null;
    private static ?array  $storefront_settings    = null;

    private ?shopPrefillPluginFillParams         $prefill_params      = null;
    private ?shopPrefillPluginSettingProvider    $setting_provider    = null;
    private ?shopPrefillPluginStorefrontProvider $storefront_provider = null;
    private ?shopPrefillPluginPluginsProvider    $plugins_provider    = null;
    private ?shopPrefillPluginUserProvider       $user_provider       = null;
    private ?shopPrefillPluginLocationProvider   $location_provider   = null;
    private ?shopPrefillPluginContactProvider    $contact_provider    = null;

    private ?shopOrderModel       $shop_order_model        = null;
    private ?shopOrderParamsModel $shop_order_params_model = null;

    private ?shopPrefillPluginOrderProvider $order_provider = null;

    private ?shopPrefillPluginSessionStorageProvider $session_storage_provider = null;

    private ?shopPrefillPluginFillParamsProvider $fill_params_provider = null;

    private ?shopPrefillPluginGuestHashStorage $guest_hash_storage = null;
    private ?shopPrefillPluginConsentStorage   $consent_storage    = null;

    private ?shopPrefillPluginZenMode $zen_mode = null;

    private ?shopPrefillPluginOrderHooks $order_hooks = null;

    private ?shopPrefillPluginFrontendHooks $frontend_hooks = null;

    private ?shopPrefillPluginAssetsManager $assets_manager = null;

    private ?shopPrefillPluginCssManager $css_manager = null;

    private ?shopPrefillPluginCheckoutHooks $checkout_hooks = null;

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
        return self::$installed_shop_plugins ??= wa('shop')->getConfig()->getPlugins();
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
        if (self::$active === null) {
            $settings = $this->getSettingProvider()->getSettings();
            shopPrefillPluginLog::setLevel($settings['logging']['level'] ?? 'warning');
            self::$active = self::enableInstall(self::PLUGIN_ID) && $settings['active'] === true;
        }
        return self::$active;
    }

    /**
     * Режим отладки плагина = глобальный debug Webasyst (несжатые ассеты, JS-логгер, служебные эндпоинты).
     */
    public function isDebug(): bool
    {
        return waSystemConfig::isDebug();
    }

    /**
     * Плавающая панель и стек хуков в head: только при глобальном debug и включённой настройке витрины prefill.debug_panel.
     */
    public function isDebugPanelEnabled(): bool
    {
        if (! waSystemConfig::isDebug()) {
            return false;
        }

        $settings = $this->getStorefrontSettings();

        return ! empty($settings['prefill']['debug_panel']);
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

        if (! file_exists($config_file)) {
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
        return self::$storefront_settings ??= $this->getStorefrontProvider()->getCurrentStorefront()->getSettings();
    }

    /**
     * Очищает статический кэш настроек витрины
     * Используется после сохранения настроек для обновления данных
     */
    public static function clearStorefrontSettingsCache(): void
    {
        self::$storefront_settings = null;
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

    public function getContactProvider(): shopPrefillPluginContactProvider
    {
        return $this->contact_provider ??= new shopPrefillPluginContactProvider();
    }

    /**
     * @throws waException
     */
    public function getFillParamsProvider(): shopPrefillPluginFillParamsProvider
    {
        return $this->fill_params_provider ??= new shopPrefillPluginFillParamsProvider(
            $this->getOrderProvider(),
            $this->getUserProvider(),
            $this->getContactProvider(),
            $this->getGuestHashStorage(),
            $this->getLocationProvider()
        );
    }

    /**
     * @throws waException
     */
    public function getGuestHashStorage(): shopPrefillPluginGuestHashStorage
    {
        return $this->guest_hash_storage ??= new shopPrefillPluginGuestHashStorage(
            $this->getUserProvider(),
            $this->getOrderParamsModel(),
            wa()->getResponse()
        );
    }

    /**
     * @throws waException
     */
    public function getConsentStorage(): shopPrefillPluginConsentStorage
    {
        return $this->consent_storage ??= new shopPrefillPluginConsentStorage(
            wa()->getResponse()
        );
    }

    public function getOrderProvider(): shopPrefillPluginOrderProvider
    {
        return $this->order_provider ??= new shopPrefillPluginOrderProvider(
            $this->getOrderModel(),
            $this->getOrderParamsModel()
        );
    }

    private function getOrderModel(): shopOrderModel
    {
        return $this->shop_order_model ??= new shopOrderModel();
    }

    private function getOrderParamsModel(): shopOrderParamsModel
    {
        return $this->shop_order_params_model ??= new shopOrderParamsModel();
    }

    /**
     * Возвращает обработчик хуков заказов
     *
     * @return shopPrefillPluginOrderHooks
     */
    public function getOrderHooks(): shopPrefillPluginOrderHooks
    {
        return $this->order_hooks ??= new shopPrefillPluginOrderHooks(
            $this->getSessionStorageProvider(),
            $this->getOrderProvider(),
            $this->getGuestHashStorage(),
            $this->getZenMode(),
            $this->getUserProvider(),
            $this->getConsentStorage(),
            $this->getStorefrontSettings(),
            wa()->getRequest()
        );
    }

    /**
     * Возвращает обработчик хуков фронтенда
     *
     * @return shopPrefillPluginFrontendHooks
     */
    public function getFrontendHooks(): shopPrefillPluginFrontendHooks
    {
        return $this->frontend_hooks ??= new shopPrefillPluginFrontendHooks(
            $this->getSessionStorageProvider(),
            $this->getFillParamsProvider(),
            $this->getUserProvider(),
            $this->getGuestHashStorage(),
            $this->getConsentStorage(),
            $this->getAssetsManager(),
            $this->isDebug(),
            $this->isDebugPanelEnabled(),
            $this->getStorefrontSettings(),
            fn($path) => $this->addCss($path),
            fn($path) => $this->addJs($path),
            $this->resolveStorefrontCssUrl()
        );
    }

    /**
     * Возвращает менеджер управления CSS/JS ресурсами
     *
     * @return shopPrefillPluginAssetsManager
     */
    public function getAssetsManager(): shopPrefillPluginAssetsManager
    {
        return $this->assets_manager ??= new shopPrefillPluginAssetsManager(self::PLUGIN_ID);
    }

    /**
     * @throws waException
     */
    public function getCssManager(): shopPrefillPluginCssManager
    {
        return $this->css_manager ??= new shopPrefillPluginCssManager(self::PLUGIN_ID, self::getPluginPath());
    }

    /**
     * Возвращает публичный URL per-storefront CSS-файла, или '' если custom_css не задан.
     * Если файл на диске отсутствует (например, после очистки wa-data) — пересоздаёт его.
     *
     * @throws waException
     * @throws waDbException
     */
    private function resolveStorefrontCssUrl(): string
    {
        $settings   = $this->getStorefrontSettings();
        $custom_css = $settings['styles']['custom_css'] ?? '';

        if ($custom_css === '') {
            shopPrefillPluginLog::debug('CSS: no custom CSS, using original frontend.css');
            return '';
        }

        $code        = $this->getStorefrontProvider()->getCurrentStorefront()->getCode();
        $css_manager = $this->getCssManager();

        if (!$css_manager->fileExists($code)) {
            // Файл мог быть удалён при очистке wa-data — пересоздаём
            $css_manager->saveFile($code, $custom_css);
        }

        $url = $css_manager->getPublicUrl($code, (int) ($settings['update_time'] ?? 0));
        shopPrefillPluginLog::debug('CSS: applying custom CSS file', [
            'storefront_code' => $code,
            'url'             => $url,
        ]);

        return $url;
    }

    /**
     * Возвращает обработчик хуков checkout процесса
     *
     * @return shopPrefillPluginCheckoutHooks
     */
    public function getCheckoutHooks(): shopPrefillPluginCheckoutHooks
    {
        return $this->checkout_hooks ??= new shopPrefillPluginCheckoutHooks(
            $this->getZenMode(),
            $this->getUserProvider(),
            $this->getConsentStorage(),
            $this->getSessionStorageProvider(),
            $this->getFillParamsProvider(),
            $this->isDebugPanelEnabled(),
            $this->getStorefrontSettings(),
            wa()->getRequest(),
            wa()->getResponse()
        );
    }


    /**
     * @throws waException
     * @throws waDbException
     */
    public function getSessionStorageProvider(): shopPrefillPluginSessionStorageProvider
    {
        return $this->session_storage_provider ??= new shopPrefillPluginSessionStorageProvider(
            wa()->getStorage(),
            $this->getUserProvider(),
            $this->getStorefrontSettings()
        );
    }

    /**
     * Возвращает координатор Zen Mode
     *
     * @return shopPrefillPluginZenMode
     * @throws waException
     * @throws waDbException
     */
    public function getZenMode(): shopPrefillPluginZenMode
    {
        if ($this->zen_mode === null) {
            $storefront_settings = $this->getStorefrontSettings();
            $view                = wa()->getView();
            $this->zen_mode      = new shopPrefillPluginZenMode(
                $storefront_settings['zen'] ?? [],
                wa()->getResponse(),
                $view,
                new shopPrefillPluginZenData($view),
                wa()->getRequest()
            );
        }
        return $this->zen_mode;
    }

    /**
     * @throws waException
     */
    public static function getStaticUrl($url = '', $absolute = false): string
    {
        return wa('shop')->getAppStaticUrl(self::APP_ID, $absolute) . 'plugins/'
            . self::PLUGIN_ID . '/' . $url;
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
        if (! $this->isActive()) {
            return '';
        }

        return $this->getFrontendHooks()->handleFrontendHead($params);
    }

    /**
     * Хук вызывается перед обработкой шага auth в processAll().
     * Срабатывает при каждом AJAX-запросе calculate/create.
     *
     * @param array $params ['data' => &$data] где $data['input'] — текущий $input processAll
     */
    public function checkoutBeforeAuth(&$params): void
    {
        if (! $this->isActive()) {
            return;
        }

        $this->getCheckoutHooks()->handleCheckoutBeforeAuth($params);
    }

    /**

     * Хук срабатывает при рендере секции авторизации на странице оформления заказа.
     * Выводит CSS для Zen Mode и показывает информацию об ошибках.
     *
     * @param array $params
     * @return string HTML для вставки в секцию авторизации
     */
    public function checkoutRenderAuth(&$params)
    {
        if (! $this->isActive()) {
            return '';
        }

        return $this->getCheckoutHooks()->handleCheckoutRenderAuth($params);
    }


    /**
     * Хук срабатывает при рендере секции региона на странице оформления заказа.
     * Показывает информацию об ошибках в секции региона.
     *
     * @param array $params
     * @return string HTML для вставки в секцию региона
     */
    public function checkoutRenderRegion(&$params)
    {
        if (! $this->isActive()) {
            return '';
        }

        return $this->getCheckoutHooks()->handleCheckoutRenderRegion($params);
    }

    /**
     * Хук срабатывает перед формированием HTML-кода шага оформления заказа «выбор способа доставки» на странице оформления заказа в корзине.
     * Выполняет предзаполнение параметров формы заказа и показывает информацию об ошибках.
     * Также может выводить блок управления zen-режимом для группы delivery, если details пустой/не существует.
     *
     * @throws waException
     * @throws SmartyException
     */
    public function checkoutRenderShipping(&$params)
    {
        if (! $this->isActive()) {
            return '';
        }

        return $this->getCheckoutHooks()->handleCheckoutRenderShipping($params);
    }

    /**
     * Хук срабатывает при рендере секции details (адресные поля доставки).
     * Выводит блок управления zen-режимом для группы delivery в конце секции (если details существует).
     *
     * @param array $params
     * @return string HTML для вставки в секцию details
     */
    public function checkoutRenderDetails(&$params)
    {
        if (! $this->isActive()) {
            return '';
        }

        return $this->getCheckoutHooks()->handleCheckoutRenderDetails($params);
    }

    /**
     * Хук срабатывает при рендере секции оплаты на странице оформления заказа.
     * Выводит блок управления для Zen Mode группы payment в конце секции.
     *
     * @param array $params
     * @return string HTML для вставки в секцию оплаты
     */
    public function checkoutRenderPayment(&$params)
    {
        if (! $this->isActive()) {
            return '';
        }

        return $this->getCheckoutHooks()->handleCheckoutRenderPayment($params);
    }

    /**
     * Хук срабатывает при рендере секции подтверждения заказа.
     * Показываем ВСЕ накопленные delayed_errors из всех предыдущих шагов.
     *
     * @param array $params
     * @return string HTML для вставки в секцию подтверждения
     */
    public function checkoutRenderConfirm(&$params)
    {
        if (! $this->isActive()) {
            return '';
        }

        return $this->getCheckoutHooks()->handleCheckoutRenderConfirm($params);
    }


    /**
     * Хук срабатывает при создании заказа.
     * Делегирует выполнение в OrderHooks.
     *
     * @param array $data Данные заказа
     * @throws waException
     */
    public function orderActionCreate($data)
    {
        if (! $this->isActive()) {
            return;
        }

        $this->getOrderHooks()->handleOrderActionCreate($data);
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
