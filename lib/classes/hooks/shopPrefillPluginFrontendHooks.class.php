<?php

/**
 * Обработчик хуков связанных с фронтендом магазина
 * Отвечает за предзаполнение и инициализацию assets на фронтенде
 */
class shopPrefillPluginFrontendHooks
{
    private shopPrefillPluginSessionStorageProvider $session_storage;
    private shopPrefillPluginFillParamsProvider     $fill_params_provider;
    private shopPrefillPluginUserProvider           $user_provider;
    private shopPrefillPluginGuestHashStorage       $guest_hash_storage;
    private shopPrefillPluginConsentStorage         $consent_storage;
    private shopPrefillPluginAssetsManager          $assets_manager;
    private bool                                    $is_debug;
    private array                                   $storefront_settings;
    /** @var callable */
    private $add_css_callback;
    /** @var callable */
    private $add_js_callback;

    public function __construct(
        shopPrefillPluginSessionStorageProvider $session_storage,
        shopPrefillPluginFillParamsProvider $fill_params_provider,
        shopPrefillPluginUserProvider $user_provider,
        shopPrefillPluginGuestHashStorage $guest_hash_storage,
        shopPrefillPluginConsentStorage $consent_storage,
        shopPrefillPluginAssetsManager $assets_manager,
        bool $is_debug,
        array $storefront_settings,
        callable $add_css_callback,
        callable $add_js_callback
    ) {
        $this->session_storage      = $session_storage;
        $this->fill_params_provider = $fill_params_provider;
        $this->user_provider        = $user_provider;
        $this->guest_hash_storage   = $guest_hash_storage;
        $this->consent_storage      = $consent_storage;
        $this->assets_manager       = $assets_manager;
        $this->is_debug             = $is_debug;
        $this->storefront_settings  = $storefront_settings;
        $this->add_css_callback     = $add_css_callback;
        $this->add_js_callback      = $add_js_callback;
    }



    /**
     * Хук срабатывает на всех страницах магазина.
     * Предзаполняет параметры при входе на сайт, управляет cookies.
     *
     * @param array|null $params Параметры хука
     * @throws waException
     * @throws waDbException
     */
    public function handleFrontendHead(?array $params = null): void
    {
        // DEBUG: Регистрируем вызов хука
        $this->registerDebugHookCall('frontendHead');

        if (! $this->storefront_settings['active']) {
            shopPrefillPluginLog::info('Skipping frontendHead: storefront is inactive');
            return;
        }

        // Получаем параметры для заполнения
        $fill_params = $this->fill_params_provider->getFillParams();

        // DEBUG: Состояние ПЕРЕД предзаполнением
        $this->logDebugBeforePrefill('frontendHead', $fill_params);

        // Управление cookies авторизации и гостевых cookies
        $this->handleRememberMeCookie();
        $this->handleGuestCookies();

        // Предзаполнение при входе на сайт
        if ($this->storefront_settings['prefill']['on_entry']) {
            shopPrefillPluginLog::info('Prefill on_entry triggered in frontendHead');
            $this->session_storage->preFillCheckoutParams($fill_params);
        }

        // DEBUG: Состояние ПОСЛЕ предзаполнения
        $this->logDebugAfterPrefill('frontendHead');

        // Инициализация стилей и скриптов
        $this->initializeFrontendAssets();
    }

    /**
     * Регистрирует вызов хука в debug-логе
     *
     * @param string $hook_name Имя хука
     */
    private function registerDebugHookCall(string $hook_name): void
    {
        if ($this->is_debug) {
            shopPrefillPluginDebug::registerHookCall($hook_name);
        }
    }

    /**
     * Логирует состояние ПЕРЕД предзаполнением
     *
     * @param string $hook_name Имя хука для метки
     * @param shopPrefillPluginFillParams|null $fill_params Параметры для заполнения
     * @throws waException
     */
    private function logDebugBeforePrefill(string $hook_name, ?shopPrefillPluginFillParams $fill_params): void
    {
        if (! $this->is_debug) {
            return;
        }

        $checkout_params_before = $this->session_storage->getCheckoutParams();
        $checkout_params_before = is_array($checkout_params_before) ? $checkout_params_before : [];

        // Получаем статус секций для отображения в дебаге
        $section_checker         = $this->session_storage->getSectionChecker();
        $sections_prefill_status = [];
        $sections_filled_status  = [];

        foreach (['auth', 'region', 'shipping', 'details', 'payment', 'confirm'] as $section_id) {
            // Собираем детальную информацию для UX цепочки
            $sections_prefill_status[$section_id] = [
                'enabled'  => $this->storefront_settings['prefill']['sections'][$section_id] ?? true,
                'filled'   => $section_checker->isSectionFilled($section_id, $checkout_params_before),
                'has_data' => $fill_params ? $fill_params->hasDataForSection($section_id) : false,
                'result'   => $section_checker->canPrefillSection($section_id, $checkout_params_before),
            ];
            $sections_filled_status[$section_id]  = $sections_prefill_status[$section_id]['filled'];
        }

        shopPrefillPluginDebug::addDebugEntry(
            $checkout_params_before,
            "BEFORE PREFILL ($hook_name)",
            [
                'sections_prefill_status' => $sections_prefill_status,
                'sections_filled_status'  => $sections_filled_status,
            ]
        );
    }

    /**
     * Логирует состояние ПОСЛЕ предзаполнения
     *
     * @param string $hook_name Имя хука для метки
     * @throws waException
     */
    private function logDebugAfterPrefill(string $hook_name): void
    {
        if (! $this->is_debug) {
            return;
        }

        $checkout_params_after = $this->session_storage->getCheckoutParams();
        $checkout_params_after = is_array($checkout_params_after) ? $checkout_params_after : [];

        // Получаем статус заполненности секций после предзаполнения
        $section_checker        = $this->session_storage->getSectionChecker();
        $sections_filled_status = [];
        foreach (['auth', 'region', 'shipping', 'details', 'payment', 'confirm'] as $section_id) {
            $sections_filled_status[$section_id] = $section_checker->isSectionFilled($section_id, $checkout_params_after);
        }

        shopPrefillPluginDebug::addDebugEntry(
            $checkout_params_after,
            "AFTER PREFILL ($hook_name)",
            ['sections_filled_status' => $sections_filled_status]
        );

        // Регистрируем отложенный вывод стека (будет выведен после всех хуков)
        shopPrefillPluginDebug::scheduleDebugStackRender();
        shopPrefillPluginDebug::renderDebugStack();
    }

    /**
     * Управляет cookie "Remember Me" для авторизованных пользователей
     *
     * @throws waException
     */
    private function handleRememberMeCookie(): void
    {
        if ($this->storefront_settings['prefill']['remember_me']['active'] && $this->user_provider->isAuth()) {
            $this->user_provider->rememberMe($this->storefront_settings['prefill']['remember_me']['expires']);
        }
    }

    /**
     * Управляет cookies для гостей (хеш и согласие)
     *
     * @throws waException
     * @throws waDbException
     */
    private function handleGuestCookies(): void
    {
        if ($this->user_provider->isAuth()) {
            return;
        }

        // Продлеваем cookie хеша гостя при каждом визите
        $this->guest_hash_storage->getOrCreateGuestHash();

        // Продлеваем cookie согласия (если оно было дано)
        // Вызов hasConsent() автоматически продлевает cookie
        $this->consent_storage->hasConsent();
    }

    /**
     * Инициализирует стили и скрипты на фронтенде
     * Делегирует в AssetsManager
     *
     * @throws waException
     */
    private function initializeFrontendAssets(): void
    {
        $css_variables = [
            'prefill-accent-color' => $this->storefront_settings['styles']['accent_color'],
        ];

        // Добавляем переменную для скрытия элементов шапки авторизации в Дзен-режиме
        if ($this->isAuthHeaderHidden()) {
            $css_variables['prefill-auth-header-display'] = 'none';
        }

        // Добавляем переменные для размера иконок в Zen Mode (если активен и иконки отображаются)
        if (!empty($this->storefront_settings['zen']['active'])
            && ($this->storefront_settings['zen']['icon_display'] ?? 'plugin') !== 'none'
        ) {
            $icon_size = $this->storefront_settings['zen']['icon_size'] ?? 'medium';
            $dimensions = $this->getIconSizeDimensions($icon_size);
            $css_variables['prefill-zen-icon-width'] = $dimensions['width'];
            $css_variables['prefill-zen-icon-height'] = $dimensions['height'];
        }

        $js_params = [
            'pluginID'                  => shopPrefillPlugin::PLUGIN_ID,
            'appUrl'                    => wa()->getAppUrl('shop'),
            'isDebug'                   => $this->is_debug,
            'isAuth'                    => $this->user_provider->isAuth(),
            'myDeliveryVariantsEnabled' => $this->storefront_settings['prefill']['my_delivery_variants'] ?? true,
            'zenButtonClasses'          => $this->storefront_settings['prefill']['my_delivery_variants_button_classes'] ?? '',
            'messages'                  => [
                'validation_error_title'      => _wp('zen.validation.error.title'),
                'validation_error_message'    => _wp('zen.validation.error.message'),
                'validation_error_button'     => _wp('zen.validation.error.button'),
                'dialog_choose_delivery'      => _wp('dialog.header.choose_delivery'),
                'delivery_unavailable_title'  => _wp('dialog.delivery_unavailable.title'),
                'delivery_unavailable_text'   => _wp('dialog.delivery_unavailable.text'),
                'delivery_unavailable_button' => _wp('dialog.delivery_unavailable.button'),
                'consent_revoke_title'       => _wp('dialog.consent_revoke.title'),
                'consent_revoke_text'        => _wp('dialog.consent_revoke.text'),
                'consent_revoke_confirm'     => _wp('dialog.consent_revoke.confirm'),
                'consent_revoke_cancel'       => _wp('dialog.consent_revoke.cancel'),
            ],
        ];

        $this->assets_manager->init(
            $this->is_debug,
            $css_variables,
            $js_params,
            $this->add_css_callback,
            $this->add_js_callback
        );
    }

    /**
     * Проверяет, нужно ли скрывать элементы шапки авторизации (только в Zen Mode и для авторизованных)
     *
     * @return bool
     */
    private function isAuthHeaderHidden(): bool
    {
        return ! empty($this->storefront_settings['zen']['active'])
            && ! empty($this->storefront_settings['zen']['hide_auth_header'])
            && $this->user_provider->isAuth();
    }

    /**
     * Возвращает размеры иконок для заданного пресета
     *
     * @param string $size Пресет размера: 'small' | 'medium' | 'large'
     * @return array{width: string, height: string}
     */
    private function getIconSizeDimensions(string $size): array
    {
        $sizes = [
            'small'  => ['width' => '2.5rem', 'height' => '1.5rem'],
            'medium' => ['width' => '3.5rem', 'height' => '2rem'],
            'large'  => ['width' => '4.5rem', 'height' => '2.5rem'],
        ];

        return $sizes[$size] ?? $sizes['medium'];
    }
}

