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

    private static ?shopPrefillPluginStorefront $effective_storefront          = null;
    private static ?array                       $effective_storefront_settings = null;

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

    private ?shopPrefillPluginGuestTokenStorage $guest_token_storage = null;
    private ?shopPrefillPluginConsentStorage   $consent_storage    = null;

    private ?shopPrefillPluginZenMode $zen_mode = null;

    private ?shopPrefillPluginOrderHooks $order_hooks = null;

    private ?shopPrefillPluginFrontendHooks $frontend_hooks = null;

    private ?shopPrefillPluginAssetsManager $assets_manager = null;

    private ?shopPrefillPluginCssManager $css_manager = null;

    private ?shopPrefillPluginCheckoutHooks $checkout_hooks = null;

    private ?shopPrefillPluginCheckoutPageDetector $checkout_page_detector = null;

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

        $settings = $this->getEffectiveStorefrontSettings();

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
     * Возвращает витрину, настройки которой действуют в текущем запросе (request-scoped кэш).
     *
     * Это текущая витрина, а если её нет (бэкенд, API, CLI) или она неактивна — глобальная '*'.
     * Неактивность конкретной витрины трактуем как «ещё не настроена», а не «явно отключена»:
     * это позволяет включить плагин один раз глобально, не активируя каждую витрину вручную.
     * Отключение конкретной витрины работает только если её global-аналог тоже неактивен.
     *
     * Единственное место, где живёт этот фоллбэк: и настройки, и код витрины берутся
     * у одного объекта, иначе они разъезжаются (per-storefront CSS-файл с глобальным содержимым).
     *
     * Важно: метод предназначен для фронтенда и хуков. Для чтения/записи настроек в админке
     * используй getStorefrontProvider()->findStorefront($code), чтобы видеть реальные данные
     * конкретной витрины без фоллбэка.
     *
     * @throws waException
     * @throws waDbException
     */
    public function getEffectiveStorefront(): shopPrefillPluginStorefront
    {
        if (self::$effective_storefront === null) {
            $provider   = $this->getStorefrontProvider();
            $storefront = $provider->findCurrentStorefront();

            if ($storefront === null || !$storefront->getSettings()['active']) {
                $storefront = $provider->getGlobalStorefront();
            }

            self::$effective_storefront = $storefront;
        }

        return self::$effective_storefront;
    }

    /**
     * Настройки эффективной витрины — в отличие от shopPrefillPluginStorefront::getSettings()
     * учитывают фоллбэк на глобальную витрину.
     *
     * @throws waException
     * @throws waDbException
     */
    public function getEffectiveStorefrontSettings(): array
    {
        return self::$effective_storefront_settings ??= $this->getEffectiveStorefront()->getSettings();
    }

    /**
     * Очищает статический кэш эффективной витрины и её настроек
     * Используется после сохранения настроек для обновления данных
     */
    public static function clearEffectiveStorefrontCache(): void
    {
        self::$effective_storefront          = null;
        self::$effective_storefront_settings = null;
    }

    /**
     * Пришёл ли запрос с витрины магазина.
     *
     * Плагин работает только с оформлением заказа на витрине: в бэкенде, API и CLI
     * нет ни checkout-сессии, ни гостевых cookie покупателя.
     *
     * @throws waException
     */
    private function isStorefrontRequest(): bool
    {
        return $this->getStorefrontProvider()->hasCurrentStorefront();
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
            $this->getGuestTokenStorage(),
            $this->getLocationProvider()
        );
    }

    /**
     * @throws waException
     */
    public function getGuestTokenStorage(): shopPrefillPluginGuestTokenStorage
    {
        return $this->guest_token_storage ??= new shopPrefillPluginGuestTokenStorage(
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
            $this->getGuestTokenStorage(),
            $this->getZenMode(),
            $this->getUserProvider(),
            $this->getConsentStorage(),
            $this->getEffectiveStorefrontSettings()
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
            $this->getUserProvider(),
            $this->getGuestTokenStorage(),
            $this->getConsentStorage(),
            $this->getAssetsManager(),
            $this->getCheckoutPageDetector(),
            $this->getZenMode(),
            $this->isDebug(),
            $this->isDebugPanelEnabled(),
            $this->getEffectiveStorefrontSettings(),
            fn($path) => $this->addCss($path),
            fn($path) => $this->addJs($path),
            fn() => $this->resolveStorefrontCssUrl()
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
        // Настройки и код берём у одного объекта: при фоллбэке на глобальную витрину
        // отдать её CSS под кодом текущей витрины — значит навсегда закешировать копию,
        // которая не обновится при следующем сохранении общих настроек.
        $storefront = $this->getEffectiveStorefront();
        $settings   = $storefront->getSettings();
        $custom_css = $settings['styles']['custom_css'] ?? '';

        if ($custom_css === '') {
            shopPrefillPluginLog::debug('CSS: no custom CSS, using original frontend.css');
            return '';
        }

        $code        = $storefront->getCode();
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
            $this->getEffectiveStorefrontSettings(),
            wa()->getRequest(),
            wa()->getResponse()
        );
    }


    /**
     * Признак «текущий запрос рендерит форму заказа» — общий для checkout-хуков,
     * которые его выставляют, и для frontend_head, который по нему решает,
     * подключать ли CSS/JS плагина.
     */
    private function getCheckoutPageDetector(): shopPrefillPluginCheckoutPageDetector
    {
        return $this->checkout_page_detector ??= new shopPrefillPluginCheckoutPageDetector(
            static fn() => [waRequest::param('module'), waRequest::param('action')]
        );
    }

    /**
     * Единая точка входа всех checkout-хуков: попутно отмечает, что запрос идёт
     * по пути оформления заказа. frontend_head сработает позже — макет рендерится
     * после шаблона экшена — и по этой отметке подключит ассеты.
     */
    private function enterCheckoutHooks(): shopPrefillPluginCheckoutHooks
    {
        $this->getCheckoutPageDetector()->markCheckoutHookFired();

        return $this->getCheckoutHooks();
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
            $this->getEffectiveStorefrontSettings()
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
            $storefront_settings = $this->getEffectiveStorefrontSettings();
            $view                = wa()->getView();
            $this->zen_mode      = new shopPrefillPluginZenMode(
                $storefront_settings['zen'] ?? [],
                wa()->getResponse(),
                $view,
                new shopPrefillPluginZenData($view),
                wa()->getRequest(),
                $this->getSessionStorageProvider(),
                new shopPrefillPluginZenSummaryCache(wa()->getStorage())
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

        $this->enterCheckoutHooks()->handleCheckoutBeforeAuth($params);
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

        return $this->enterCheckoutHooks()->handleCheckoutRenderAuth($params);
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

        return $this->enterCheckoutHooks()->handleCheckoutRenderRegion($params);
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

        return $this->enterCheckoutHooks()->handleCheckoutRenderShipping($params);
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

        return $this->enterCheckoutHooks()->handleCheckoutRenderDetails($params);
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

        return $this->enterCheckoutHooks()->handleCheckoutRenderPayment($params);
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

        return $this->enterCheckoutHooks()->handleCheckoutRenderConfirm($params);
    }


    /**
     * Хук срабатывает при создании заказа — в том числе в бэкенде, API, CLI и импорте.
     * Делегирует выполнение в OrderHooks.
     *
     * @param array $data Данные заказа
     * @throws waException
     */
    public function orderActionCreate($data)
    {
        // Проверка витрины идёт первой: вне фронтенда сохранять нечего (нет checkout-сессии),
        // а гостевой хеш из cookie администратора приклеился бы к заказу покупателя.
        if (! $this->isStorefrontRequest() || ! $this->isActive()) {
            return;
        }

        // waEvent::runPlugins() ловит только Exception: любой Error (TypeError, вызов на null)
        // ушёл бы наверх и уронил создание заказа 500-й. Предзаполнение следующего заказа
        // не стоит того, чтобы магазин терял оформленный заказ.
        try {
            $this->getOrderHooks()->handleOrderActionCreate($data);
        } catch (Throwable $e) {
            shopPrefillPluginLog::error('Order creation hook failed', [
                'order_id' => $data['order_id'] ?? null,
                'message'  => $e->getMessage(),
                'file'     => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    /**
     * @throws waException
     */
    public function saveSettings($settings = array())
    {
        if (isset($settings['storefront'])) {
            foreach ($settings['storefront'] as $storefront_code => $storefront_settings) {
                $storefront = $this->getStorefrontProvider()->findStorefront((string) $storefront_code);

                // Витрину могли удалить или переименовать после открытия формы настроек —
                // пропускаем её, но сохраняем настройки остальных
                if ($storefront === null) {
                    shopPrefillPluginLog::warning('Skipping settings for unknown storefront', [
                        'storefront_code' => $storefront_code,
                    ]);
                    continue;
                }

                $storefront->saveSettings($storefront_settings);
            }
            unset($settings['storefront']);
        }

        $this->getSettingProvider()->saveSettings($settings);
    }

}
