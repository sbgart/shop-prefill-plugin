<?php

/**
 * Обработчик хуков связанных с фронтендом магазина
 * Отвечает за предзаполнение и инициализацию assets на фронтенде
 */
class shopPrefillPluginFrontendHooks
{
    private shopPrefillPluginSessionStorageProvider $session_storage;
    private shopPrefillPluginUserProvider           $user_provider;
    private shopPrefillPluginGuestTokenStorage      $guest_token_storage;
    private shopPrefillPluginConsentStorage         $consent_storage;
    private shopPrefillPluginAssetsManager          $assets_manager;
    private bool                                    $is_debug;
    private bool                                    $is_debug_panel;
    private array                                   $storefront_settings;
    private string                                  $storefront_css_url;
    /** @var callable */
    private $add_css_callback;
    /** @var callable */
    private $add_js_callback;

    public function __construct(
        shopPrefillPluginSessionStorageProvider $session_storage,
        shopPrefillPluginUserProvider $user_provider,
        shopPrefillPluginGuestTokenStorage $guest_token_storage,
        shopPrefillPluginConsentStorage $consent_storage,
        shopPrefillPluginAssetsManager $assets_manager,
        bool $is_debug,
        bool $is_debug_panel,
        array $storefront_settings,
        callable $add_css_callback,
        callable $add_js_callback,
        string $storefront_css_url = ''
    ) {
        $this->session_storage      = $session_storage;
        $this->user_provider        = $user_provider;
        $this->guest_token_storage  = $guest_token_storage;
        $this->consent_storage      = $consent_storage;
        $this->assets_manager       = $assets_manager;
        $this->is_debug             = $is_debug;
        $this->is_debug_panel       = $is_debug_panel;
        $this->storefront_settings  = $storefront_settings;
        $this->add_css_callback     = $add_css_callback;
        $this->add_js_callback      = $add_js_callback;
        $this->storefront_css_url   = $storefront_css_url;
    }



    /**
     * Хук срабатывает на всех страницах магазина.
     *
     * Предзаполнением НЕ занимается: запись сессии отсюда всё равно не может повлиять
     * на текущую страницу (хук вызывается из лэйаута, после сборки $content), а вне
     * чекаута предзаполненные секции никто не читает. Источник предзаполнения теперь
     * читается только на чекаут-пути — см. docs/codereview/issue-63-*.md и
     * docs/todo/on-entry-early-prefill.md.
     *
     * Здесь остаются куки, remember-me, ассеты и debug-панель.
     *
     * @param array|null $params Параметры хука
     * @return string HTML для вставки в <head>
     * @throws waException
     * @throws waDbException
     */
    public function handleFrontendHead(?array $params = null): string
    {
        $head_html = '';

        // DEBUG: Регистрируем вызов хука
        $this->registerDebugHookCall('frontendHead');

        if (! $this->storefront_settings['active']) {
            shopPrefillPluginLog::debug('Skipping frontendHead: storefront is inactive');
            return '';
        }

        // Авторизация не зависит от гостевых настроек, поэтому обрабатывается до раннего
        // выхода ниже: иначе одноразовая метка pending auth зависла бы в сессии.
        $this->handleRememberMeCookie();

        // Для гостей: пропускаем если функция отключена
        $guest_enabled = $this->storefront_settings['prefill']['guest']['enabled'];
        if (!$guest_enabled && !$this->user_provider->isAuth()) {
            shopPrefillPluginLog::debug('Skipping frontendHead: guest prefill is disabled');
            return $head_html;
        }

        // Управление гостевыми cookies
        $this->handleGuestCookies();

        // Инициализация стилей и скриптов
        $this->initializeFrontendAssets();

        if ($this->is_debug_panel) {
            $head_html .= shopPrefillPluginDebug::renderDebugStack();
        }

        return $head_html;
    }

    /**
     * Регистрирует вызов хука в debug-логе
     *
     * @param string $hook_name Имя хука
     */
    private function registerDebugHookCall(string $hook_name): void
    {
        if ($this->is_debug_panel) {
            shopPrefillPluginDebug::registerHookCall($hook_name);
        }
    }

    /**
     * Управляет cookie авторизации `auth_token`.
     *
     * Два независимых сценария:
     *  A. Покупатель сам отметил «Запомнить меня» — фреймворк уже выдал токен,
     *     мы только продлеваем его до заданного срока.
     *  B. Покупателя авторизовало оформление заказа, где галочки нет вовсе —
     *     токен выдаём сами, но лишь по явно включённой настройке магазина.
     *
     * @throws waException
     */
    private function handleRememberMeCookie(): void
    {
        $settings = $this->storefront_settings['prefill']['remember_me'] ?? [];
        $expires  = (int) ($settings['expires'] ?? 0);

        // Метка одноразовая — гасим её всегда, даже если продлевать не будем
        $pending_auth = $this->session_storage->consumePendingAuth();

        // Авторизация могла не состояться (бан, ошибка) — метка просто сгорает
        if (! $this->user_provider->isAuth()) {
            return;
        }

        // Без «Запомнить меня» на домене waAuth::_authByCookie() не читает auth_token,
        // так что выдавать его бессмысленно.
        if (! $this->user_provider->isDomainRememberMeEnabled()) {
            if ($pending_auth) {
                shopPrefillPluginLog::warning(
                    'Cannot keep customer signed in: "remember me" is disabled for this domain'
                );
            }
            return;
        }

        // Сценарий B
        if ($pending_auth && !empty($settings['on_order'])) {
            $this->user_provider->rememberMe($expires);
            shopPrefillPluginLog::info('Auth token issued after order confirmation', [
                'expires_days' => $expires,
            ]);
            return;
        }

        // Сценарий A: наличие токена — единственный надёжный признак согласия покупателя
        if (!empty($settings['active']) && $this->user_provider->hasAuthToken()) {
            $this->user_provider->rememberMe($expires);
            shopPrefillPluginLog::debug('Auth token extended', ['expires_days' => $expires]);
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

        if (!$this->storefront_settings['prefill']['guest']['enabled']) {
            return;
        }

        // Продлеваем куку гостя, если она есть. Новую здесь НЕ создаём:
        // токен выдаётся только при первом завершённом заказе, поэтому посетитель
        // без истории не получает идентификатор за просмотр каталога.
        $this->guest_token_storage->extendToken();

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
            'prefill-accent-color-light' => $this->storefront_settings['styles']['accent_color'],
            'prefill-accent-color-dark'  => $this->storefront_settings['styles']['accent_color_dark'],
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
            'myDeliveryVariantsButtonClasses' => $this->storefront_settings['prefill']['my_delivery_variants_button_classes'] ?? '',
            'messages'                  => [
                'validation_error_title'      => _wp('zen.validation.error.title'),
                'validation_error_message'    => _wp('zen.validation.error.message'),
                'validation_error_button'     => _wp('zen.validation.error.button'),
                'dialog_choose_delivery'      => _wp('dialog.header.choose_delivery'),
                'delivery_unavailable_title'  => _wp('dialog.delivery_unavailable.title'),
                'delivery_unavailable_text'   => _wp('dialog.delivery_unavailable.text'),
                'delivery_unavailable_button' => _wp('dialog.delivery_unavailable.button'),
                'params_choice_link'          => _wp('dialog.params_choice.link'),
                'params_choice_link_tooltip'  => _wp('dialog.params_choice.link_tooltip'),
                'dialog_content_loading'      => _wp('dialog.content.loading'),
                'dialog_content_error'        => _wp('dialog.content.error'),
                'consent_revoke_title'       => _wp('dialog.consent_revoke.title'),
                'consent_revoke_text'        => _wp('dialog.consent_revoke.text'),
                'consent_revoke_confirm'     => _wp('dialog.consent_revoke.confirm'),
                'consent_revoke_cancel'       => _wp('dialog.consent_revoke.cancel'),
            ],
        ];

        // Несжатые ассеты и isDebug в JS (логгер) — по глобальному debug; панель — по is_debug_panel
        $this->assets_manager->init(
            $this->is_debug,
            $css_variables,
            $js_params,
            $this->add_css_callback,
            $this->add_js_callback,
            $this->storefront_css_url
        );
    }

    /**
     * Проверяет, нужно ли скрывать элементы шапки авторизации:
     * Zen включён, группа «Покупатель» сворачивается, флаг hide_auth_header, пользователь авторизован.
     *
     * @return bool
     */
    private function isAuthHeaderHidden(): bool
    {
        $zen      = $this->storefront_settings['zen'] ?? [];
        $customer = $zen['groups']['customer'] ?? [];

        return ! empty($zen['active'])
            && ! empty($customer['enabled'])
            && ! empty($customer['hide_auth_header'])
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

