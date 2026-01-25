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
    private ?shopPrefillPluginContactProvider $contact_provider = null;

    private ?shopOrderModel $shop_order_model = null;
    private ?shopOrderParamsModel $shop_order_params_model = null;

    private ?shopPrefillPluginOrderProvider $order_provider = null;

    private ?shopPrefillPluginSessionStorageProvider $session_storage_provider = null;

    private ?shopPrefillPluginFillParamsProvider $fill_params_provider = null;

    private ?shopPrefillPluginGuestHashStorage $guest_hash_storage = null;
    private ?shopPrefillPluginConsentStorage $consent_storage = null;

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
            $this->getLocationProvider(),
            wa()->getResponse()
        );
    }

    /**
     * @throws waException
     */
    public function getGuestHashStorage(): shopPrefillPluginGuestHashStorage
    {
        return $this->guest_hash_storage ??= new shopPrefillPluginGuestHashStorage(
            $this->getUserProvider(),
            new shopOrderParamsModel(),
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
            $this->getStorefrontSettings()
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
        $css_variables_map = shopPrefillPluginViewProvider::createCssVariablesString($css_variables);
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

        // DEBUG: Регистрируем вызов хука
        if ($this->isDebug()) {
            shopPrefillPluginDebugHelper::registerHookCall('frontendOrder');
        }

        $storefront_settings = $this->getStorefrontSettings();

        if ($storefront_settings['active'] !== true) {
            return;
        }

        // Получаем параметры для заполнения заранее DLYA DEBUG и предзаполнения
        $fill_params = null;
        if ($storefront_settings['prefill']['active']) {
            $fill_params = $this->getFillParamsProvider()->getFillParams();
        }

        // DEBUG: Добавляем состояние хранилища ПЕРЕД предзаполнением
        if ($this->isDebug()) {
            $checkout_params_before = $this->getSessionStorageProvider()->getCheckoutParams();
            $checkout_params_before = is_array($checkout_params_before) ? $checkout_params_before : [];

            // Получаем статус секций для отображения в дебаге
            $section_checker = $this->getSessionStorageProvider()->getSectionChecker();
            $sections_prefill_status = [];
            $sections_filled_status = []; // Оставляем для совместимости, но данные есть и в prefill_status
            foreach (['auth', 'region', 'shipping', 'details', 'payment', 'confirm'] as $section_id) {
                // Собираем детальную информацию для UX цепочки
                $sections_prefill_status[$section_id] = [
                    'enabled' => $storefront_settings['prefill']['sections'][$section_id] ?? true,
                    'filled' => $section_checker->isSectionFilled($section_id, $checkout_params_before),
                    'has_data' => $fill_params ? $fill_params->hasDataForSection($section_id) : false,
                    'result' => $section_checker->canPrefillSection($section_id, $checkout_params_before),
                ];
                $sections_filled_status[$section_id] = $sections_prefill_status[$section_id]['filled'];
            }

            shopPrefillPluginDebugHelper::addDebugEntry(
                $checkout_params_before,
                'BEFORE PREFILL (frontendOrder)',
                [
                    'sections_prefill_status' => $sections_prefill_status,
                    'sections_filled_status' => $sections_filled_status,
                ]
            );
        }

        if ($storefront_settings['prefill']['active'] && $fill_params) {
            $this->getSessionStorageProvider()->preFillCheckoutParams($fill_params);
        }

        // DEBUG: Добавляем состояние хранилища ПОСЛЕ предзаполнения и регистрируем отложенный рендер
        if ($this->isDebug()) {
            $checkout_params_after = $this->getSessionStorageProvider()->getCheckoutParams();
            $checkout_params_after = is_array($checkout_params_after) ? $checkout_params_after : [];

            // Получаем статус заполненности секций после предзаполнения
            $section_checker = $this->getSessionStorageProvider()->getSectionChecker();
            $sections_filled_status = [];
            foreach (['auth', 'region', 'shipping', 'details', 'payment', 'confirm'] as $section_id) {
                $sections_filled_status[$section_id] = $section_checker->isSectionFilled($section_id, $checkout_params_after);
            }

            shopPrefillPluginDebugHelper::addDebugEntry(
                $checkout_params_after,
                'AFTER PREFILL (frontendOrder)',
                ['sections_filled_status' => $sections_filled_status]
            );

            // Регистрируем отложенный вывод стека (будет выведен после всех хуков)
            shopPrefillPluginDebugHelper::scheduleDebugStackRender();
            shopPrefillPluginDebugHelper::renderDebugStack();
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

        // DEBUG: Регистрируем вызов хука
        if ($this->isDebug()) {
            shopPrefillPluginDebugHelper::registerHookCall('frontendHead');
        }
        //wa()->getStorage()->set('shop/checkout', '');

        $storefront_settings = $this->getStorefrontSettings();

        if (!$storefront_settings['active']) {
            return;
        }

        // Получаем параметры для заполнения заранее DLYA DEBUG и предзаполнения
        $fill_params = null;
        if ($storefront_settings['prefill']['active']) {
            $fill_params = $this->getFillParamsProvider()->getFillParams();
        }

        // DEBUG: Добавляем состояние хранилища ПЕРЕД предзаполнением
        if ($this->isDebug()) {
            $checkout_params_before = $this->getSessionStorageProvider()->getCheckoutParams();
            $checkout_params_before = is_array($checkout_params_before) ? $checkout_params_before : [];

            // Получаем статус секций для отображения в дебаге
            $section_checker = $this->getSessionStorageProvider()->getSectionChecker();
            $sections_prefill_status = [];
            $sections_filled_status = [];
            foreach (['auth', 'region', 'shipping', 'details', 'payment', 'confirm'] as $section_id) {
                // Собираем детальную информацию для UX цепочки
                $sections_prefill_status[$section_id] = [
                    'enabled' => $storefront_settings['prefill']['sections'][$section_id] ?? true,
                    'filled' => $section_checker->isSectionFilled($section_id, $checkout_params_before),
                    'has_data' => $fill_params ? $fill_params->hasDataForSection($section_id) : false,
                    'result' => $section_checker->canPrefillSection($section_id, $checkout_params_before),
                ];
                $sections_filled_status[$section_id] = $sections_prefill_status[$section_id]['filled'];
            }

            shopPrefillPluginDebugHelper::addDebugEntry(
                $checkout_params_before,
                'BEFORE PREFILL (frontendHead)',
                [
                    'sections_prefill_status' => $sections_prefill_status,
                    'sections_filled_status' => $sections_filled_status,
                ]
            );
        }

        // Создаем или обновляем куки авторизации пользователя.
        if ($storefront_settings['remember_me']['active'] && $this->getUserProvider()->isAuth()) {
            $this->getUserProvider()->rememberMe($storefront_settings['remember_me']['expires']);
        }

        // Для неавторизованных: продлеваем cookie хеша гостя и согласия при каждом визите
        // Это обеспечивает автоматическое продление срока жизни обоих cookies (1 год)
        if (!$this->getUserProvider()->isAuth()) {
            $this->getGuestHashStorage()->getOrCreateGuestHash();

            // Продлеваем cookie согласия (если оно было дано)
            // Вызов hasConsent() автоматически продлевает cookie
            $this->getConsentStorage()->hasConsent();
        }

        // Предзаполнение включено, заполняем параметры корзины при входе на сайт
        if ($storefront_settings['prefill']['active']) {
            if ($storefront_settings['prefill']['on_entry']) {
                $this->getSessionStorageProvider()->preFillCheckoutParams(
                    $this->getFillParamsProvider()->getFillParams()
                );
            }
        }

        // DEBUG: Добавляем состояние хранилища ПОСЛЕ предзаполнения и регистрируем отложенный рендер
        if ($this->isDebug()) {
            $checkout_params_after = $this->getSessionStorageProvider()->getCheckoutParams();
            $checkout_params_after = is_array($checkout_params_after) ? $checkout_params_after : [];

            // Получаем статус заполненности секций после предзаполнения
            $section_checker = $this->getSessionStorageProvider()->getSectionChecker();
            $sections_filled_status = [];
            foreach (['auth', 'region', 'shipping', 'details', 'payment', 'confirm'] as $section_id) {
                $sections_filled_status[$section_id] = $section_checker->isSectionFilled($section_id, $checkout_params_after);
            }

            shopPrefillPluginDebugHelper::addDebugEntry(
                $checkout_params_after,
                'AFTER PREFILL (frontendHead)',
                ['sections_filled_status' => $sections_filled_status]
            );

            // Регистрируем отложенный вывод стека (будет выведен после всех хуков)
            shopPrefillPluginDebugHelper::scheduleDebugStackRender();
            shopPrefillPluginDebugHelper::renderDebugStack();
        }

        // Инициализируем стили и скрипты.
        $css_variables = [
            'prefill-accent-color' => $storefront_settings['styles']['accent_color'],
        ];

        $js_params = [
            'pluginID' => $this::PLUGIN_ID,
            'appUrl' => wa()->getAppUrl('shop'),  // Базовый URL приложения Shop-Script
            'isDebug' => $this->isDebug(),
        ];

        self::frontendAssetsInit($css_variables, $js_params);
    }

    /**
     * Хук срабатывает при рендере секции авторизации на странице оформления заказа.
     * Показывает информацию об ошибках в секции авторизации.
     *
     * @param array $params
     * @return string HTML для вставки в секцию авторизации
     */
    public function checkoutRenderAuth(&$params)
    {
        if (!$this->isActive()) {
            return '';
        }

        // Извлекаем все типы ошибок
        $errors_info = $this->extractCheckoutErrors($params);

        // Если нет ошибок - ничего не показываем
        if (!$errors_info['has_errors']) {
            return '';
        }

        // Есть ошибки - показываем debug информацию
        return shopPrefillPluginDebugHelper::renderErrorsDebugHtml($errors_info, 'AUTH SECTION');
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
        if (!$this->isActive()) {
            return '';
        }

        // Извлекаем все типы ошибок
        $errors_info = $this->extractCheckoutErrors($params);

        // Если нет ошибок - ничего не показываем
        if (!$errors_info['has_errors']) {
            return '';
        }

        // Есть ошибки - показываем debug информацию
        return shopPrefillPluginDebugHelper::renderErrorsDebugHtml($errors_info, 'REGION SECTION');
    }

    /**
     * Хук срабатывает перед формированием HTML-кода шага оформления заказа «выбор способа доставки» на странице оформления заказа в корзине.
     * Выполняет предзаполнение параметров формы заказа и показывает информацию об ошибках.
     *
     * @throws waException
     * @throws SmartyException
     */
    public function checkoutRenderShipping(&$params)
    {
        // Check if plugin is active
        if (!$this->isActive()) {
            return '';
        }

        // Извлекаем все типы ошибок
        $errors_info = $this->extractCheckoutErrors($params);

        // Если нет ошибок - ничего не показываем
        if (!$errors_info['has_errors']) {
            return '';
        }

        // Есть ошибки - показываем debug информацию
        return shopPrefillPluginDebugHelper::renderErrorsDebugHtml($errors_info, 'SHIPPING SECTION');
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
        if (!$this->isActive()) {
            return '';
        }

        $html = '';

        // Показываем галочку согласия только для неавторизованных И если требуется согласие
        try {
            if (!$this->getUserProvider()->isAuth()) {
                $storefront_settings = $this->getStorefrontSettings();
                $consent_required = $storefront_settings['guest']['consent_required'];

                // Показываем галочку только если согласие требуется
                if ($consent_required) {
                    $has_consent = $this->getConsentStorage()->hasConsent();
                    $html .= shopPrefillPluginViewProvider::render(
                        'checkout/ConsentCheckbox',
                        ['has_consent' => $has_consent]
                    );
                }
            }
        } catch (Exception $e) {
            // Игнорируем ошибки рендеринга галочки
        }

        // Извлекаем все типы ошибок
        $errors_info = $this->extractCheckoutErrors($params);

        // Если есть ошибки - показываем debug информацию
        if ($errors_info['has_errors']) {
            $html .= shopPrefillPluginDebugHelper::renderErrorsDebugHtml($errors_info, 'CONFIRM SECTION');
        }

        return $html;
    }

    /**
     * Извлекает все типы ошибок из $params массива checkout хука.
     * Используется для определения, можно ли безопасно скрывать поля формы.
     *
     * @param array $params Массив параметров из checkout хука
     * @return array Структурированный массив с информацией об ошибках
     */
    private function extractCheckoutErrors(array $params): array
    {
        // Собираем ВСЕ delayed_errors из всех шагов
        $auth_delayed_errors = ifset($params, 'data', 'auth', 'delayed_errors', []);
        $details_delayed_errors = ifset($params, 'data', 'details', 'delayed_errors', []);

        // Проверяем ОБЫЧНЫЕ ошибки (критические, блокирующие)
        $regular_errors = ifset($params, 'errors', []);
        $error_step_id = ifset($params, 'error_step_id', null);

        // Проверяем auth[service_agreement] - чекбокс согласия с условиями
        // Значение = 0 означает НЕ установлен, = 1 означает установлен
        $service_agreement_error = false;
        $service_agreement_value = ifset($params, 'vars', 'auth', 'service_agreement', null);

        // Если service_agreement существует и равен 0 - пользователь НЕ согласился
        if ($service_agreement_value !== null && $service_agreement_value == 0) {
            $service_agreement_error = true;
        }

        $all_delayed_errors = array_merge($auth_delayed_errors, $details_delayed_errors);
        $has_errors = !empty($all_delayed_errors) || !empty($regular_errors) || $service_agreement_error;

        return [
            'has_errors' => $has_errors,
            'regular_errors' => $regular_errors,
            'auth_delayed_errors' => $auth_delayed_errors,
            'details_delayed_errors' => $details_delayed_errors,
            'service_agreement_error' => $service_agreement_error,
            'error_step_id' => $error_step_id,
        ];
    }


    /**
     * Хук срабатывает при создании заказа.
     * Сохраняем дополнительные параметры заказа и хеш гостя для предзаполнения.
     *
     * @throws waException
     */
    public function orderActionCreate($data)
    {
        if (!$this->isActive()) {
            return;
        }

        if (!isset($data['order_id'])) {
            return;
        }

        $order_id = (int) $data['order_id'];
        $checkout_params = $this->getSessionStorageProvider()->getCheckoutParams();

        // Сохраняем shipping_type_id
        $this->getOrderProvider()->storeShippingTypeId(
            $order_id,
            (int) ($checkout_params['order']['shipping']['type_id'] ?? 0)
        );

        // Сохраняем комментарий
        $comment = $checkout_params['order']['confirm']['comment'] ?? '';
        $this->getOrderProvider()->storeComment($order_id, $comment);

        // Для неавторизованных: сохраняем хеш гостя
        // Логика: если согласие не требуется ИЛИ оно получено - сохраняем хеш
        if (!$this->getUserProvider()->isAuth()) {
            $storefront_settings = $this->getStorefrontSettings();
            $consent_required = $storefront_settings['guest']['consent_required'];
            $has_consent = $this->getConsentStorage()->hasConsent();

            // Сохраняем хеш если: согласие не требуется ИЛИ оно получено
            if (!$consent_required || $has_consent) {
                $guest_hash = $this->getGuestHashStorage()->getOrCreateGuestHash();
                $this->getGuestHashStorage()->saveGuestHashToOrder($order_id, $guest_hash);
            }
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
