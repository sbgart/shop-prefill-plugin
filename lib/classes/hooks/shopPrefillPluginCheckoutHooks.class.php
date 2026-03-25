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
    private waRequest $request;
    private waResponse $response;

    public function __construct(
        shopPrefillPluginZenMode $zen_mode,
        shopPrefillPluginUserProvider $user_provider,
        shopPrefillPluginConsentStorage $consent_storage,
        shopPrefillPluginSessionStorageProvider $session_storage,
        shopPrefillPluginFillParamsProvider $fill_params_provider,
        bool $is_debug,
        array $storefront_settings,
        waRequest $request,
        waResponse $response
    ) {
        $this->zen_mode = $zen_mode;
        $this->user_provider = $user_provider;
        $this->consent_storage = $consent_storage;
        $this->session_storage = $session_storage;
        $this->fill_params_provider = $fill_params_provider;
        $this->is_debug = $is_debug;
        $this->storefront_settings = $storefront_settings;
        $this->request = $request;
        $this->response = $response;
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
        $fill_params = $this->fill_params_provider->getFillParams();
        $filled_order = $this->session_storage->preFillCheckoutParams($fill_params);

        if (!empty($filled_order)) {
            $state = new shopPrefillCheckoutState($params);
            $state->applyPrefillInput($filled_order);
            if ($state->isPrefilled()) {
                shopPrefillPluginLog::info('Prefill applied in checkoutBeforeAuth');
            }
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
        $state = new shopPrefillCheckoutState($params);
        return $this->renderZenModeStylesheet()
            . $this->buildZenModeGroupBlock('customer', $state, 'checkoutRenderAuth')
            . $this->renderSectionErrorsAndDebug($state, 'checkoutRenderAuth', 'AUTH SECTION');
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
        $state = new shopPrefillCheckoutState($params);
        return $this->renderSectionErrorsAndDebug($state, 'checkoutRenderRegion', 'REGION SECTION');
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
        $state = new shopPrefillCheckoutState($params);
        return $this->renderSectionErrorsAndDebug($state, 'checkoutRenderShipping', 'SHIPPING SECTION');
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
        $state = new shopPrefillCheckoutState($params);
        return $this->buildZenModeGroupBlock('delivery', $state, 'checkoutRenderDetails')
            . $this->renderSectionErrorsAndDebug($state, 'checkoutRenderDetails', 'DETAILS SECTION');
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
        $state = new shopPrefillCheckoutState($params);
        return $this->buildZenModeGroupBlock('payment', $state, 'checkoutRenderPayment')
            . $this->renderSectionErrorsAndDebug($state, 'checkoutRenderPayment', 'PAYMENT SECTION');
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
        $state = new shopPrefillCheckoutState($params);
        return $this->renderDeliveryUnavailableScript($state)
            . $this->renderZenModeConfirmStyles($state)
            . $this->renderConsentCheckbox()
            . $this->renderSectionErrorsAndDebug($state, 'checkoutRenderConfirm', 'CONFIRM SECTION');
    }

    /**
     * Проверяет, был ли выбран вариант доставки пользователем (кука prefill_user_selected),
     * и если shipping[type_id] после этого не заполнился — выдаёт inline script
     * с триггером события prefill_delivery_unavailable для JS.
     *
     * Куку гасит PHP только при успешном предзаполнении (shipping заполнен).
     * В случае сигнала куку гасит JS при показе диалога — это позволяет скрипту
     * дожить в HTML через несколько AJAX-запросов checkout до финального рендера.
     */
    private function renderDeliveryUnavailableScript(shopPrefillCheckoutState $state): string
    {
        if ($this->request->cookie('prefill_user_selected') !== '1') {
            return '';
        }


        if ($state->getShippingType() !== '') {
            // Доставка успешно заполнена — гасим куку server-side
            $this->response->setCookie('prefill_user_selected', '', -1, '/');
            return '';
        }

        // Shipping не применим — сигнализируем JS; куку гасит JS при показе диалога
        return '<script>$(document).trigger("prefill_delivery_unavailable");</script>';
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
     * Строит блок Zen Mode для группы: синхронизация cookie + рендер.
     *
     * @param string $group Имя группы (customer, delivery, payment)
     * @param array $params Параметры хука
     * @param string $log_context Контекст для логирования ошибок
     * @return string HTML блока управления или пустая строка
     */
    private function buildZenModeGroupBlock(string $group, shopPrefillCheckoutState $state, string $log_context): string
    {
        try {
            if (!$this->zen_mode->isGroupEnabled($group)) {
                return '';
            }
            return $this->zen_mode->buildCollapseBlock($group, $state);
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
    private function renderZenModeConfirmStyles(shopPrefillCheckoutState $state): string
    {
        try {
            $groups_to_collapse = $this->zen_mode->getGroupsToCollapse($state);
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
            if ($this->user_provider->isAuth()) {
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
    private function renderSectionErrorsAndDebug(shopPrefillCheckoutState $state, string $hook_name, string $section_label): string
    {
        $errors_info = $state->getAllErrorsInfo();

        if ($this->is_debug) {
            shopPrefillPluginDebug::addDebugEntry(
                $state->getData(),
                'CHECKOUT HOOK (' . $hook_name . ')',
                ['errors_info' => $errors_info]
            );
        }

        if (!$errors_info['has_errors']) {
            return '';
        }

        return shopPrefillPluginDebug::renderErrorsDebugHtml($errors_info, $section_label);
    }

}

