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
    private bool $is_debug;
    private array $storefront_settings;

    public function __construct(
        shopPrefillPluginZenMode $zen_mode,
        shopPrefillPluginUserProvider $user_provider,
        shopPrefillPluginConsentStorage $consent_storage,
        bool $is_debug,
        array $storefront_settings
    ) {
        $this->zen_helper = $zen_mode;
        $this->user_provider = $user_provider;
        $this->consent_storage = $consent_storage;
        $this->is_debug = $is_debug;
        $this->storefront_settings = $storefront_settings;
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

        // === ZEN MODE: Добавляем JavaScript в первом хуке ===
        try {
            // Добавляем JavaScript только один раз (в первом хуке)
            $output .= $this->zen_helper->generateJavaScript();

            // Рендерим блок управления для группы customer в КОНЦЕ секции
            if ($this->zen_helper->shouldCollapseGroup('customer', $params)) {
                // СВЁРНУТО: сводка + "Изменить"
                $output .= $this->zen_helper->renderCollapseBlock('customer', $params, true);
            } elseif ($this->zen_helper->isGroupEnabled('customer')) {
                // РАЗВЁРНУТО (любая причина): только "Свернуть"
                $output .= $this->zen_helper->renderCollapseBlock('customer', $params, false);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки Zen Mode
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

        // === ZEN MODE: Рендерим блок управления ===
        // ВАЖНО: для группы delivery рендерим блок в details (если есть) или в shipping (если details нет)
        try {
            // Проверяем, будет ли хук checkoutRenderDetails вызван
            $has_details = $this->hasDetailsSection($params);

            // Если секции details НЕТ - рендерим блок управления delivery ЗДЕСЬ (в shipping)
            if (!$has_details) {
                if ($this->zen_helper->shouldCollapseGroup('delivery', $params)) {
                    // СВЁРНУТО: сводка + "Изменить"
                    $output .= $this->zen_helper->renderCollapseBlock('delivery', $params, true);
                } elseif ($this->zen_helper->isGroupEnabled('delivery')) {
                    // РАЗВЁРНУТО (любая причина): только "Свернуть"
                    $output .= $this->zen_helper->renderCollapseBlock('delivery', $params, false);
                }
            }
        } catch (Exception $e) {
            // Игнорируем ошибки Zen Mode
        }

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
            if ($this->zen_helper->shouldCollapseGroup('delivery', $params)) {
                // СВЁРНУТО: сводка + "Изменить"
                $output .= $this->zen_helper->renderCollapseBlock('delivery', $params, true);
            } elseif ($this->zen_helper->isGroupEnabled('delivery')) {
                // РАЗВЁРНУТО (любая причина): только "Свернуть"
                $output .= $this->zen_helper->renderCollapseBlock('delivery', $params, false);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки Zen Mode
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
            if ($this->zen_helper->shouldCollapseGroup('payment', $params)) {
                // СВЁРНУТО: сводка + "Изменить"
                $output .= $this->zen_helper->renderCollapseBlock('payment', $params, true);
            } elseif ($this->zen_helper->isGroupEnabled('payment')) {
                // РАЗВЁРНУТО (любая причина): только "Свернуть"
                $output .= $this->zen_helper->renderCollapseBlock('payment', $params, false);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки Zen Mode
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

        // === ZEN MODE: Генерируем CSS для ВСЕХ групп в последнем хуке ===
        // Здесь у нас точно есть все данные об ошибках
        try {
            $html .= $this->zen_helper->generateAllStyles($params);
        } catch (Exception $e) {
            // Игнорируем ошибки Zen Mode
        }

        // Показываем галочку согласия только для неавторизованных И если требуется согласие
        try {
            if (!$this->user_provider->isAuth()) {
                $consent_required = $this->storefront_settings['guest']['consent_required'];

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
            // Игнорируем ошибки рендеринга галочки
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

    /**
     * Проверяет, существует ли секция details (адресные поля доставки)
     *
     * Секция details может отсутствовать если:
     * - Доставка отключена (shipping.used = false)
     * - Нет адресных полей для выбранной доставки
     * - Выбран пункт выдачи без адресных полей
     *
     * @param array $params Параметры checkout хука
     * @return bool
     */
    private function hasDetailsSection(array $params): bool
    {
        // Проверяем, используется ли доставка
        $shipping_used = ifset($params, 'data', 'shipping', 'used', false);
        if (!$shipping_used) {
            return false;
        }

        // Проверяем, существует ли секция details в $params['steps']
        // Если она существует - значит Webasyst планирует её рендерить
        $steps = ifset($params, 'steps', []);
        return in_array('details', $steps, true);
    }
}
