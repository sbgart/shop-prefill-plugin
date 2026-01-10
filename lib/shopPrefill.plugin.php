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
    private static bool    $frontend_assets_inited = false;

    private ?shopPrefillPluginFillParams         $prefill_params      = null;
    private ?shopPrefillPluginSettingProvider    $setting_provider    = null;
    private ?shopPrefillPluginStorefrontProvider $storefront_provider = null;
    private ?shopPrefillPluginPluginsProvider    $plugins_provider    = null;
    private ?shopPrefillPluginUserProvider       $user_provider       = null;
    private ?shopPrefillPluginLocationProvider   $location_provider   = null;

    private ?shopOrderModel       $shop_order_model        = null;
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
        if (! self::$frontend_assets_inited) {
            $is_debug = $this->isDebug();
            $this->addCss('css/frontend.' . (! $is_debug ? 'min.' : '') . 'css');
            $this->addJs('js/frontend.' . (! $is_debug ? 'min.' : '') . 'js?');

            if (! empty($css_variables)) {
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
        $css_variables_map      = shopPrefillPluginViewProvider::createCssVariablesString($css_variables);
        $css_variables_filename = 'variables_' . md5($css_variables_map) . '.css';
        $css_public_dir         = wa()->getDataPath('plugins/' . self::PLUGIN_ID . '/css/', true, 'shop');

        if (! file_exists($css_public_dir . $css_variables_filename)) {
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
        $js_file_name  = md5($inline_script) . '.js';
        $js_public_dir = wa()->getDataPath('plugins/' . self::PLUGIN_ID . '/js/', true, 'shop');
        if (! file_exists($js_public_dir . $js_file_name)) {
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
        if (! $this->isActive()) {
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
        if (! $this->isActive()) {
            return;
        }
        //wa()->getStorage()->set('shop/checkout', '');

        $storefront_settings = $this->getStorefrontSettings();

        if (! $storefront_settings['active']) {
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
     * Хук срабатывает при рендере секции авторизации на странице оформления заказа.
     * Добавляет кнопку выбора параметров в секцию авторизации.
     *
     * @param array $params
     * @return string HTML для вставки в секцию авторизации
     */
    public function checkoutRenderAuth(&$params)
    {
        if (! $this->isActive()) {
            return '';
        }

        // Проверяем наличие delayed_errors в auth
        $auth_delayed_errors = ifset($params, 'data', 'auth', 'delayed_errors', []);

        $debug_html  = '<div style="background: lightblue; padding: 20px; margin: 10px; border: 2px solid blue;">';
        $debug_html .= '<strong>🎉 TEST IN AUTH SECTION!</strong>';
        $debug_html .= '<p>Вставлено в секцию авторизации через хук checkout_render_auth</p>';

        if ($auth_delayed_errors) {
            $debug_html .= '<div style="background: #ffcccc; padding: 10px; margin-top: 10px; border: 1px solid red;">';
            $debug_html .= '<strong>⚠️ DELAYED ERRORS:</strong><pre>';
            $debug_html .= htmlspecialchars(print_r($auth_delayed_errors, true));
            $debug_html .= '</pre></div>';
        } else {
            $debug_html .= '<p style="color: green;">✅ Нет delayed_errors в auth</p>';
        }

        $debug_html .= '</div>';

        return $debug_html;
    }

    /**
     * Хук срабатывает при рендере секции региона на странице оформления заказа.
     * Добавляет кнопку выбора параметров в секцию региона.
     *
     * @param array $params
     * @return string HTML для вставки в секцию региона
     */
    public function checkoutRenderRegion(&$params)
    {
        if (! $this->isActive()) {
            return '';
        }

        // Ничего не возвращаем, используем только auth секцию
        return '';
    }

    /**
     * Хук срабатывает перед формированием HTML-кода шага оформления заказа «выбор способа доставки» на странице оформления заказа в корзине.
     * Выполняет предзаполнение параметров формы заказа и добавляет ссылку на вызов диалога выбора способа доставки.
     *
     * @throws waException
     * @throws SmartyException
     */
    public function checkoutRenderShipping(&$params)
    {
        // Check if plugin is active
        if (! $this->isActive()) {
            return '';
        }

        // Сохраняем параметры предзаполнения в кэш для последующего использования.
        $this->getFillParamsStorage()->storeFillParams(
            $this->getFillParamsProvider()->getFillParamsByCheckoutParams(
                $this->getSessionStorageProvider()->getCheckoutParams()
            )
        );

        // DEBUG: Проверяем все delayed_errors
        $auth_delayed_errors    = ifset($params, 'data', 'auth', 'delayed_errors', []);
        $details_delayed_errors = ifset($params, 'data', 'details', 'delayed_errors', []);

        if ($auth_delayed_errors || $details_delayed_errors) {
            $debug_html  = '<div style="background: #fff3cd; padding: 20px; margin: 10px; border: 2px solid orange;">';
            $debug_html .= '<strong>⚠️ DELAYED ERRORS В SHIPPING SECTION!</strong>';

            if ($auth_delayed_errors) {
                $debug_html .= '<div style="background: #ffcccc; padding: 10px; margin-top: 10px; border: 1px solid red;">';
                $debug_html .= '<strong>Auth errors:</strong><pre style="font-size: 11px;">';
                $debug_html .= htmlspecialchars(print_r($auth_delayed_errors, true));
                $debug_html .= '</pre></div>';
            }

            if ($details_delayed_errors) {
                $debug_html .= '<div style="background: #ffcccc; padding: 10px; margin-top: 10px; border: 1px solid red;">';
                $debug_html .= '<strong>Details errors:</strong><pre style="font-size: 11px;">';
                $debug_html .= htmlspecialchars(print_r($details_delayed_errors, true));
                $debug_html .= '</pre></div>';
            }

            $debug_html .= '</div>';
            return $debug_html;
        }

        // Ничего не возвращаем для секции доставки
        return '';
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

        // ВРЕМЕННЫЙ DEBUG: Выводим всю структуру auth для поиска service_agreement
        $debug_auth_structure = '';
        if (isset($params['data']['auth'])) {
            $debug_auth_structure  = '<div style="background: #e3f2fd; padding: 10px; margin: 10px; border: 2px solid #2196f3; border-radius: 5px;">';
            $debug_auth_structure .= '<strong>🔍 DEBUG: Структура $params[\'data\'][\'auth\']:</strong>';
            $debug_auth_structure .= '<pre style="font-size: 11px; overflow-x: auto;">';
            $debug_auth_structure .= htmlspecialchars(print_r($params['data']['auth'], true));
            $debug_auth_structure .= '</pre></div>';
        }

        // Собираем ВСЕ delayed_errors из всех шагов
        $auth_delayed_errors    = ifset($params, 'data', 'auth', 'delayed_errors', []);
        $details_delayed_errors = ifset($params, 'data', 'details', 'delayed_errors', []);

        // Проверяем ОБЫЧНЫЕ ошибки (критические, блокирующие)
        $regular_errors = ifset($params, 'errors', []);
        $error_step_id  = ifset($params, 'error_step_id', null);

        // Проверяем auth[service_agreement] - чекбокс согласия с условиями
        // Если пользователь не согласился - нельзя скрывать форму
        $service_agreement_error = false;
        if (isset($params['data']['auth'])) {
            $auth_data = $params['data']['auth'];
            // Проверяем есть ли поле service_agreement и не заполнено ли оно
            if (isset($auth_data['fields']) && is_array($auth_data['fields'])) {
                foreach ($auth_data['fields'] as $field) {
                    if (isset($field['name']) && $field['name'] === 'service_agreement') {
                        // Если поле обязательное (required) и не заполнено - это ошибка
                        if (!empty($field['required']) && empty($auth_data['service_agreement'])) {
                            $service_agreement_error = true;
                            break;
                        }
                    }
                }
            }
        }

        $all_delayed_errors = array_merge($auth_delayed_errors, $details_delayed_errors);

        if (! $all_delayed_errors && ! $regular_errors && ! $service_agreement_error) {
            $debug_html  = $debug_auth_structure; // Выводим debug структуру
            $debug_html .= '<div style="background: #d4edda; padding: 15px; margin: 10px; border: 2px solid green; border-radius: 5px;">';
            $debug_html .= '<strong>✅ CONFIRM SECTION: Все поля заполнены корректно!</strong>';
            $debug_html .= '<p style="margin: 5px 0 0 0; color: #155724;">Нет ошибок - можно безопасно скрывать поля.</p>';
            $debug_html .= '</div>';
            return $debug_html;
        }

        // Есть незаполненные обязательные поля
        $debug_html  = $debug_auth_structure; // Выводим debug структуру ВСЕГДА
        $debug_html .= '<div style="background: #f8d7da; padding: 15px; margin: 10px; border: 2px solid #dc3545; border-radius: 5px;">';
        $debug_html .= '<strong>⚠️ CONFIRM SECTION: Обнаружены незаполненные обязательные поля!</strong>';
        $debug_html .= '<p style="margin: 5px 0 10px 0; color: #721c24;">Нельзя скрывать поля - пользователь не сможет их заполнить!</p>';

        // КРИТИЧЕСКИЕ ОШИБКИ (блокируют checkout, влияют на расчет доставки)
        if ($regular_errors) {
            $debug_html .= '<div style="background: #ffcccc; padding: 10px; margin-top: 10px; border: 2px solid #dc3545; border-radius: 3px;">';
            $debug_html .= '<strong>🚨 КРИТИЧЕСКИЕ ОШИБКИ (блокируют checkout):</strong>';
            if ($error_step_id) {
                $debug_html .= '<p style="margin: 5px 0; font-size: 12px;">Шаг с ошибкой: <code>' . htmlspecialchars($error_step_id) . '</code></p>';
            }
            $debug_html .= '<ul style="margin: 5px 0; padding-left: 20px;">';
            foreach ($regular_errors as $error) {
                $field_name  = ifset($error, 'name', 'unknown');
                $error_text  = ifset($error, 'text', 'Unknown error');
                $section     = ifset($error, 'section', '');
                $debug_html .= '<li><code>' . htmlspecialchars($field_name) . '</code>';
                if ($section) {
                    $debug_html .= ' <span style="font-size: 11px; color: #666;">(' . htmlspecialchars($section) . ')</span>';
                }
                $debug_html .= ': ' . htmlspecialchars($error_text) . '</li>';
            }
            $debug_html .= '</ul>';
            $debug_html .= '<p style="margin: 5px 0 0 0; font-size: 12px; color: #721c24;"><strong>Важно:</strong> Эти поля влияют на расчет стоимости/доступности доставки</p>';
            $debug_html .= '</div>';
        }

        // ОТЛОЖЕННЫЕ ОШИБКИ (не блокируют, но проверяются при создании заказа)
        if ($auth_delayed_errors) {
            $debug_html .= '<div style="background: #fff3cd; padding: 10px; margin-top: 10px; border: 1px solid #ffc107; border-radius: 3px;">';
            $debug_html .= '<strong>📝 Auth errors (секция авторизации):</strong>';
            $debug_html .= '<ul style="margin: 5px 0; padding-left: 20px;">';
            foreach ($auth_delayed_errors as $field_name => $error_text) {
                $debug_html .= '<li><code>' . htmlspecialchars($field_name) . '</code>: ' . htmlspecialchars($error_text) . '</li>';
            }
            $debug_html .= '</ul></div>';
        }

        // SERVICE AGREEMENT ERROR (чекбокс согласия с условиями)
        if ($service_agreement_error) {
            $debug_html .= '<div style="background: #ffebee; padding: 10px; margin-top: 10px; border: 2px solid #f44336; border-radius: 3px;">';
            $debug_html .= '<strong>⚠️ Service Agreement (чекбокс согласия с условиями):</strong>';
            $debug_html .= '<p style="margin: 5px 0; padding-left: 20px; color: #c62828;">';
            $debug_html .= '<code>auth[service_agreement]</code>: Пользователь должен согласиться с условиями обслуживания';
            $debug_html .= '</p></div>';
        }

        if ($details_delayed_errors) {
            $debug_html .= '<div style="background: #fff3cd; padding: 10px; margin-top: 10px; border: 1px solid #ffc107; border-radius: 3px;">';
            $debug_html .= '<strong>🚚 Details errors (секция доставки):</strong>';
            $debug_html .= '<ul style="margin: 5px 0; padding-left: 20px;">';
            foreach ($details_delayed_errors as $field_name => $error_text) {
                $debug_html .= '<li><code>' . htmlspecialchars($field_name) . '</code>: ' . htmlspecialchars($error_text) . '</li>';
            }
            $debug_html .= '</ul></div>';
        }

        $debug_html .= '<div style="background: #e7f3ff; padding: 10px; margin-top: 10px; border: 1px solid #0066cc; border-radius: 3px;">';
        $debug_html .= '<strong>💡 Решение:</strong> Не скрывать блоки формы, если есть ЛЮБЫЕ ошибки (критические или delayed)';
        $debug_html .= '</div>';

        $debug_html .= '</div>';

        return $debug_html;
    }

    /**
     * Хук срабатывает при создании заказа.
     * Сохраняем shipping_type_id в параметры заказа.
     *
     * @throws waException
     */
    public function orderActionCreate($data)
    {
        if (! $this->isActive()) {
            return;
        }

        // Сохраняем дополнительные параметры заказа.
        $checkout_params = $this->getSessionStorageProvider()->getCheckoutParams();

        // TODO: Ведь можно сделать что бы и не для зареганных юзеров сохранялись параметры

        if (isset($data['order_id'])) {
            $this->getOrderProvider()->storeShippingTypeId(
                (int) $data['order_id'],
                (int) $checkout_params['order']['shipping']['type_id']
            );
            $comment = $checkout_params['order']['confirm']['comment'] ?? '';
            $this->getOrderProvider()->storeComment(
                (int) $data['order_id'],
                $comment
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
