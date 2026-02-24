<?php

/**
 * Обработчик хуков связанных с процессом checkout
 * Отвечает за рендеринг Zen Mode блоков и отображение debug информации
 */
class shopPrefillPluginCheckoutHooks
{
    private shopPrefillPluginZenMode $zen_mode;
    private shopPrefillPluginUserProvider $user_provider;
    private shopPrefillPluginConsentStorage $consent_storage;
    private shopPrefillPluginSessionStorageProvider $session_storage;
    private shopPrefillPluginFillParamsProvider $fill_params_provider;
    private bool $is_debug;
    private array $storefront_settings;

    public function __construct(
        shopPrefillPluginZenMode $zen_mode,
        shopPrefillPluginUserProvider $user_provider,
        shopPrefillPluginConsentStorage $consent_storage,
        shopPrefillPluginSessionStorageProvider $session_storage,
        shopPrefillPluginFillParamsProvider $fill_params_provider,
        bool $is_debug,
        array $storefront_settings
    ) {
        $this->zen_mode = $zen_mode;
        $this->user_provider = $user_provider;
        $this->consent_storage = $consent_storage;
        $this->session_storage = $session_storage;
        $this->fill_params_provider = $fill_params_provider;
        $this->is_debug = $is_debug;
        $this->storefront_settings = $storefront_settings;
    }

    /**
     * Хук вызывается перед обработкой шага auth в processAll().
     * Срабатывает при каждом AJAX-запросе calculate/create.
     *
     * Выполняет две задачи:
     * 1. Записывает prefill-данные в сессию (для следующего use_session_input запроса)
     * 2. Применяет prefill-данные к $params['data']['input'] для ТЕКУЩЕГО processAll
     *
     * @param array $params ['data' => &$data] где $data['input'] — текущий $input processAll
     */
    public function handleCheckoutBeforeAuth(array &$params): void
    {
        if (!($this->storefront_settings['prefill']['active'] ?? false)) {
            return;
        }

        $fill_params = $this->fill_params_provider->getFillParams();
        if (!$fill_params) {
            return;
        }

        $filled_order = $this->session_storage->preFillCheckoutParams($fill_params);

        if (!empty($filled_order) && isset($params['data']['input'])) {
            shopPrefillPluginLog::info('Prefill applied in checkoutBeforeAuth');
            $params['data']['input'] = shopPrefillPluginHelper::deepMergeArrays(
                $params['data']['input'],
                $filled_order
            );
        }
    }

    /**
     * Хук срабатывает при рендере секции авторизации на странице оформления заказа.
     * Добавляет JavaScript Zen Mode и рендерит блок управления для группы customer.
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию авторизации
     * @throws waException
     * @throws SmartyException
     */
    public function handleCheckoutRenderAuth(array &$params): string
    {
        $output = '';

        // === ZEN MODE: Подключаем CSS только на чекауте (один раз) ===
        if ($this->zen_mode->isActive()) {
            $plugin_url = wa()->getAppStaticUrl('shop') . 'plugins/prefill/';
            $output .= '<link rel="stylesheet" href="' . $plugin_url . 'css/zenmode.css">';
        }

        // === ZEN MODE: Рендер блока управления для группы customer ===
        try {
            if ($this->zen_mode->shouldCollapseGroup('customer', $params)) {
                // СВЁРНУТО: сводка + "Изменить"
                $output .= $this->zen_mode->renderCollapseBlock('customer', $params, true);
            } elseif ($this->zen_mode->isGroupEnabled('customer')) {
                // РАЗВЁРНУТО (любая причина): только "Свернуть"
                $output .= $this->zen_mode->renderCollapseBlock('customer', $params, false);
            }
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Zen Mode error in checkoutRenderAuth', [
                'message' => $e->getMessage()
            ]);
        }

        // Извлекаем все типы ошибок
        $errors_info = $this->extractCheckoutErrors($params);

        // DEBUG: Добавляем запись в debug stack с данными об ошибках
        if ($this->is_debug) {
            $checkout_params = ifset($params, 'data', []);
            shopPrefillPluginDebug::addDebugEntry(
                $checkout_params,
                'CHECKOUT HOOK (checkoutRenderAuth)',
                ['errors_info' => $errors_info]
            );
        }

        // Если есть ошибки - показываем debug информацию
        if ($errors_info['has_errors']) {
            $output .= shopPrefillPluginDebug::renderErrorsDebugHtml($errors_info, 'AUTH SECTION');
        }

        return $output;
    }

    /**
     * Хук срабатывает при рендере секции региона на странице оформления заказа.
     * Показывает информацию об ошибках в секции региона.
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию региона
     */
    public function handleCheckoutRenderRegion(array &$params): string
    {
        $output = '';

        // Извлекаем все типы ошибок
        $errors_info = $this->extractCheckoutErrors($params);

        // DEBUG: Добавляем запись в debug stack с данными об ошибках
        if ($this->is_debug) {
            $checkout_params = ifset($params, 'data', []);
            shopPrefillPluginDebug::addDebugEntry(
                $checkout_params,
                'CHECKOUT HOOK (checkoutRenderRegion)',
                ['errors_info' => $errors_info]
            );
        }

        // Если есть ошибки - показываем debug информацию
        if ($errors_info['has_errors']) {
            $output .= shopPrefillPluginDebug::renderErrorsDebugHtml($errors_info, 'REGION SECTION');
        }

        return $output;
    }

    /**
     * Хук срабатывает перед формированием HTML-кода шага оформления заказа «выбор способа доставки».
     * Выполняет предзаполнение параметров формы заказа и показывает информацию об ошибках.
     * Также может выводить блок управления zen-режимом для группы delivery, если details пустой/не существует.
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию доставки
     * @throws waException
     * @throws SmartyException
     */
    public function handleCheckoutRenderShipping(array &$params): string
    {
        $output = '';

        // Извлекаем все типы ошибок
        $errors_info = $this->extractCheckoutErrors($params);

        // DEBUG: Добавляем запись в debug stack с данными об ошибках
        if ($this->is_debug) {
            $checkout_params = ifset($params, 'data', []);
            shopPrefillPluginDebug::addDebugEntry(
                $checkout_params,
                'CHECKOUT HOOK (checkoutRenderShipping)',
                ['errors_info' => $errors_info]
            );
        }

        // Если есть ошибки - показываем debug информацию
        if ($errors_info['has_errors']) {
            $output .= shopPrefillPluginDebug::renderErrorsDebugHtml($errors_info, 'SHIPPING SECTION');
        }

        return $output;
    }

    /**
     * Хук срабатывает при рендере секции адреса доставки на странице оформления заказа.
     * Рендерит блок управления для Zen Mode группы delivery в конце секции.
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию адреса
     * @throws waException
     * @throws SmartyException
     */
    public function handleCheckoutRenderDetails(array &$params): string
    {
        $output = '';

        // === ZEN MODE: Рендерим блок управления для группы delivery в КОНЦЕ секции details ===
        try {
            if ($this->zen_mode->shouldCollapseGroup('delivery', $params)) {
                // СВЁРНУТО: сводка + "Изменить"
                $output .= $this->zen_mode->renderCollapseBlock('delivery', $params, true);
            } elseif ($this->zen_mode->isGroupEnabled('delivery')) {
                // РАЗВЁРНУТО (любая причина): только "Свернуть"
                $output .= $this->zen_mode->renderCollapseBlock('delivery', $params, false);
            }
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Zen Mode error in checkoutRenderDetails', [
                'message' => $e->getMessage()
            ]);
        }

        // Извлекаем все типы ошибок
        $errors_info = $this->extractCheckoutErrors($params);

        // DEBUG: Добавляем запись в debug stack с данными об ошибках
        if ($this->is_debug) {
            $checkout_params = ifset($params, 'data', []);
            shopPrefillPluginDebug::addDebugEntry(
                $checkout_params,
                'CHECKOUT HOOK (checkoutRenderDetails)',
                ['errors_info' => $errors_info]
            );
        }

        // Если есть ошибки - показываем debug информацию
        if ($errors_info['has_errors']) {
            $output .= shopPrefillPluginDebug::renderErrorsDebugHtml($errors_info, 'DETAILS SECTION');
        }

        return $output;
    }

    /**
     * Хук срабатывает при рендере секции оплаты на странице оформления заказа.
     * Выводит блок управления для Zen Mode группы payment в конце секции.
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию оплаты
     * @throws waException
     * @throws SmartyException
     */
    public function handleCheckoutRenderPayment(array &$params): string
    {
        $output = '';

        // === ZEN MODE: Рендерим блок управления для группы payment ===
        try {
            if ($this->zen_mode->shouldCollapseGroup('payment', $params)) {
                // СВЁРНУТО: сводка + "Изменить"
                $output .= $this->zen_mode->renderCollapseBlock('payment', $params, true);
            } elseif ($this->zen_mode->isGroupEnabled('payment')) {
                // РАЗВЁРНУТО (любая причина): только "Свернуть"
                $output .= $this->zen_mode->renderCollapseBlock('payment', $params, false);
            }
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Zen Mode error in checkoutRenderPayment', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }

        // Извлекаем все типы ошибок
        $errors_info = $this->extractCheckoutErrors($params);

        // DEBUG: Добавляем запись в debug stack
        if ($this->is_debug) {
            $checkout_params = ifset($params, 'data', []);
            shopPrefillPluginDebug::addDebugEntry(
                $checkout_params,
                'CHECKOUT HOOK (checkoutRenderPayment)',
                ['errors_info' => $errors_info]
            );
        }

        // Если есть ошибки - показываем debug информацию
        if ($errors_info['has_errors']) {
            $output .= shopPrefillPluginDebug::renderErrorsDebugHtml($errors_info, 'PAYMENT SECTION');
        }

        return $output;
    }

    /**
     * Хук срабатывает при рендере секции подтверждения на странице оформления заказа.
     * Генерирует CSS для всех групп Zen Mode и показывает галочку согласия для гостей.
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию подтверждения
     * @throws waException
     * @throws SmartyException
     */
    public function handleCheckoutRenderConfirm(array &$params): string
    {
        $html = '';

        // === ZEN MODE: Генерируем CSS для свернутых групп в последнем хуке ===
        try {
            $groups_to_collapse = $this->zen_mode->getGroupsToCollapse($params);
            $html .= $this->zen_mode->generateAllStyles($groups_to_collapse);
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Zen Mode styling error in checkoutRenderConfirm', [
                'message' => $e->getMessage()
            ]);
        }

        // Показываем галочку согласия только для неавторизованных, если prefill и consent_required включены
        try {
            if ($this->storefront_settings['prefill']['active'] && !$this->user_provider->isAuth()) {
                $consent_required = $this->storefront_settings['prefill']['guest']['consent_required'];

                // Показываем галочку только если согласие требуется
                if ($consent_required) {
                    $has_consent = $this->consent_storage->hasConsent();
                    $html .= shopPrefillPluginViewProvider::render(
                        'checkout/ConsentCheckbox',
                        ['has_consent' => $has_consent]
                    );
                }
            }
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Consent checkbox rendering error in checkoutRenderConfirm', [
                'message' => $e->getMessage()
            ]);
        }

        // Извлекаем все типы ошибок
        $errors_info = $this->extractCheckoutErrors($params);

        // DEBUG: Добавляем запись в debug stack с данными об ошибках
        if ($this->is_debug) {
            $checkout_params = ifset($params, 'data', []);
            shopPrefillPluginDebug::addDebugEntry(
                $checkout_params,
                'CHECKOUT HOOK (checkoutRenderConfirm)',
                ['errors_info' => $errors_info]
            );
        }

        // Если есть ошибки - показываем debug информацию
        if ($errors_info['has_errors']) {
            $html .= shopPrefillPluginDebug::renderErrorsDebugHtml($errors_info, 'CONFIRM SECTION');
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

}
