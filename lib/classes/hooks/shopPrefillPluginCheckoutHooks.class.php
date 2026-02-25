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
     */
    public function handleCheckoutRenderAuth(array &$params): string
    {
        return $this->renderZenModeStylesheet()
            . $this->renderZenModeGroupBlock('customer', $params, 'checkoutRenderAuth')
            . $this->renderSectionErrorsAndDebug($params, 'checkoutRenderAuth', 'AUTH SECTION');
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
        return $this->renderSectionErrorsAndDebug($params, 'checkoutRenderRegion', 'REGION SECTION');
    }

    /**
     * Хук срабатывает перед формированием HTML-кода шага оформления заказа «выбор способа доставки».
     * Показывает информацию об ошибках.
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию доставки
     */
    public function handleCheckoutRenderShipping(array &$params): string
    {
        return $this->renderSectionErrorsAndDebug($params, 'checkoutRenderShipping', 'SHIPPING SECTION');
    }

    /**
     * Хук срабатывает при рендере секции адреса доставки на странице оформления заказа.
     * Рендерит блок управления для Zen Mode группы delivery в конце секции.
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию адреса
     */
    public function handleCheckoutRenderDetails(array &$params): string
    {
        return $this->renderZenModeGroupBlock('delivery', $params, 'checkoutRenderDetails')
            . $this->renderSectionErrorsAndDebug($params, 'checkoutRenderDetails', 'DETAILS SECTION');
    }

    /**
     * Хук срабатывает при рендере секции оплаты на странице оформления заказа.
     * Выводит блок управления для Zen Mode группы payment в конце секции.
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию оплаты
     */
    public function handleCheckoutRenderPayment(array &$params): string
    {
        return $this->renderZenModeGroupBlock('payment', $params, 'checkoutRenderPayment')
            . $this->renderSectionErrorsAndDebug($params, 'checkoutRenderPayment', 'PAYMENT SECTION');
    }

    /**
     * Хук срабатывает при рендере секции подтверждения на странице оформления заказа.
     * Генерирует CSS для всех групп Zen Mode и показывает галочку согласия для гостей.
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию подтверждения
     */
    public function handleCheckoutRenderConfirm(array &$params): string
    {
        return $this->renderZenModeConfirmStyles($params)
            . $this->renderConsentCheckbox()
            . $this->renderSectionErrorsAndDebug($params, 'checkoutRenderConfirm', 'CONFIRM SECTION');
    }

    /**
     * Рендерит тег <link> для подключения zenmode.css если Zen Mode активен.
     *
     * @return string HTML-тег подключения стилей или пустая строка
     */
    private function renderZenModeStylesheet(): string
    {
        if (!$this->zen_mode->isActive()) {
            return '';
        }

        $plugin_url = wa()->getAppStaticUrl('shop') . 'plugins/prefill/';
        return '<link rel="stylesheet" href="' . $plugin_url . 'css/zenmode.css">';
    }

    /**
     * Рендерит блок управления Zen Mode для указанной группы.
     * Определяет должна ли группа быть свёрнута и вызывает соответствующий рендер.
     *
     * @param string $group Имя группы (customer, delivery, payment)
     * @param array $params Параметры хука
     * @param string $log_context Контекст для логирования ошибок
     * @return string HTML блока управления или пустая строка
     */
    private function renderZenModeGroupBlock(string $group, array &$params, string $log_context): string
    {
        try {
            // Свёрнуто: сводка + кнопка «Изменить»
            if ($this->zen_mode->shouldCollapseGroup($group, $params)) {
                return $this->zen_mode->renderCollapseBlock($group, $params, true);
            }
            // Развёрнуто: только кнопка «Свернуть»
            if ($this->zen_mode->isGroupEnabled($group)) {
                return $this->zen_mode->renderCollapseBlock($group, $params, false);
            }
            return '';
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Zen Mode error in ' . $log_context, [
                'message' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Генерирует CSS стили для свернутых групп Zen Mode.
     * Вызывается в последнем хуке (Confirm) для генерации всех стилей сразу.
     *
     * @param array $params Параметры хука
     * @return string HTML с <style> тегом или пустая строка
     */
    private function renderZenModeConfirmStyles(array $params): string
    {
        try {
            $groups_to_collapse = $this->zen_mode->getGroupsToCollapse($params);
            return $this->zen_mode->generateAllStyles($groups_to_collapse);
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Zen Mode styling error in checkoutRenderConfirm', [
                'message' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Рендерит галочку согласия на сохранение данных для гостей.
     * Показывается только если prefill активен, пользователь не авторизован и требуется согласие.
     *
     * @return string HTML чекбокса согласия или пустая строка
     */
    private function renderConsentCheckbox(): string
    {
        try {
            if (!$this->storefront_settings['prefill']['active'] || $this->user_provider->isAuth()) {
                return '';
            }

            $consent_required = $this->storefront_settings['prefill']['guest']['consent_required'];
            if (!$consent_required) {
                return '';
            }

            $has_consent = $this->consent_storage->hasConsent();
            return shopPrefillPluginViewProvider::render(
                'checkout/ConsentCheckbox',
                ['has_consent' => $has_consent]
            );
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Consent checkbox rendering error in checkoutRenderConfirm', [
                'message' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Извлекает ошибки, добавляет debug запись и возвращает HTML блока ошибок.
     * Единый метод для всех render-хуков для обработки ошибок и debug информации.
     *
     * @param array $params Параметры хука
     * @param string $hook_name Имя хука для debug записи (например checkoutRenderAuth)
     * @param string $section_label Метка секции для вывода (например AUTH SECTION)
     * @return string HTML блока debug информации или пустая строка
     */
    private function renderSectionErrorsAndDebug(array $params, string $hook_name, string $section_label): string
    {
        $errors_info = $this->extractCheckoutErrors($params);

        if ($this->is_debug) {
            $checkout_params = ifset($params, 'data', []);
            shopPrefillPluginDebug::addDebugEntry(
                $checkout_params,
                'CHECKOUT HOOK (' . $hook_name . ')',
                ['errors_info' => $errors_info]
            );
        }

        if (!$errors_info['has_errors']) {
            return '';
        }

        return shopPrefillPluginDebug::renderErrorsDebugHtml($errors_info, $section_label);
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
