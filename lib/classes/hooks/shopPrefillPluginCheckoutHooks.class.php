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
    private bool $is_debug_panel;
    private array $storefront_settings;
    private waRequest $request;
    private waResponse $response;

    public function __construct(
        shopPrefillPluginZenMode $zen_mode,
        shopPrefillPluginUserProvider $user_provider,
        shopPrefillPluginConsentStorage $consent_storage,
        shopPrefillPluginSessionStorageProvider $session_storage,
        shopPrefillPluginFillParamsProvider $fill_params_provider,
        bool $is_debug_panel,
        array $storefront_settings,
        waRequest $request,
        waResponse $response
    ) {
        $this->zen_mode = $zen_mode;
        $this->user_provider = $user_provider;
        $this->consent_storage = $consent_storage;
        $this->session_storage = $session_storage;
        $this->fill_params_provider = $fill_params_provider;
        $this->is_debug_panel = $is_debug_panel;
        $this->storefront_settings = $storefront_settings;
        $this->request = $request;
        $this->response = $response;
        shopPrefillPluginDebug::setEnabled($is_debug_panel);
    }

    /**
     * Хук вызывается перед обработкой шага auth в processAll().
     * Срабатывает при каждом AJAX-запросе calculate/create.
     *
     * Выполняет четыре задачи:
     * 1. Записывает prefill-данные в сессию (для следующего use_session_input запроса)
     * 2. Применяет prefill-данные к $params['data']['input'] для ТЕКУЩЕГО processAll
     * 3. Восстанавливает способ оплаты, механически обнулённый ядром при смене
     *    доставки/региона — эхо-кэш секции payment (P9)
     * 4. Восстанавливает выбор доставки, стёртый коротким замыканием конвейера, —
     *    эхо-кэш группы delivery
     *
     * Оба эха пишут дважды, и оба письма обязательны: в сессию — чтобы пережить запрос
     * (её читают formVars() при загрузке страницы и ветка use_session_input), и прямо
     * в $data['input'] — потому что calculateAction() строит $input из POST и сессию
     * при обычном calculate не читает вовсе.
     *
     * @param array $params ['data' => &$data] где $data['input'] — текущий $input processAll
     */
    public function handleCheckoutBeforeAuth(array &$params): void
    {
        // Витрина неактивна — prefill-логика не выполняется.
        if (!$this->storefront_settings['active']) {
            return;
        }

        // Источник читается лениво: сначала снапшот и список пустых секций, и только
        // если пробелы остались — обращение к БД, не чаще раза на источник за сессию.
        $provider     = $this->fill_params_provider;
        $source_key   = $provider->getSourceKey();
        $source_before = $this->session_storage->getAppliedSource();
        $storage_before = $this->session_storage->getCheckoutParams();
        $checker = $this->session_storage->getSectionChecker();
        $section_decisions = [];
        foreach (['auth', 'region', 'shipping', 'details', 'payment', 'confirm'] as $section_id) {
            $available = $checker->canPrefillSection($section_id, $storage_before);
            $section_decisions[$section_id] = [
                'available' => $available,
                'reason' => $available
                    ? 'available'
                    : ($checker->isGroupEnabledForSection($section_id) ? 'owned_or_filled' : 'disabled'),
            ];
        }
        $source_loaded = false;
        $source_order_id = null;
        $filled_order = $this->session_storage->preFillCheckoutParamsFromSource(
            $source_key,
            static function () use ($provider, &$source_loaded, &$source_order_id) {
                $source_loaded = true;
                $params = $provider->getFillParams();
                $source_order_id = $params->getId();
                return $params;
            }
        );

        foreach ($section_decisions as $section_id => &$decision) {
            if (!$decision['available']) {
                continue;
            }
            if ($source_key === null) {
                $decision['reason'] = 'source_absent';
            } elseif ($source_before === $source_key) {
                $decision['reason'] = 'source_already_applied';
            } elseif (array_key_exists($section_id, $filled_order)) {
                $decision['reason'] = 'applied';
            } else {
                $decision['reason'] = $source_loaded ? 'no_source_data' : 'not_observed';
            }
        }
        unset($decision);

        shopPrefillPluginDebug::recordEvent('prefill', 'checkout_before_auth', [
            'source_key' => $source_key,
            'applied_source_before' => $source_before,
            'source_loaded' => $source_loaded,
            'source_order_id' => $source_order_id,
            'sections' => $section_decisions,
            'applied_sections' => array_keys($filled_order),
            'session_changed_paths' => $this->findChangedPaths(
                $storage_before,
                $this->session_storage->getCheckoutParams()
            ),
            'input_changed_paths' => $this->listLeafPaths($filled_order),
        ]);

        if (!empty($filled_order)) {
            $state = new shopPrefillCheckoutState($params);
            $state->applyPrefillInput($filled_order);
            if ($state->isPrefilled()) {
                shopPrefillPluginLog::debug('Prefill applied in checkoutBeforeAuth', [
                    'sections' => array_keys($filled_order),
                ]);
            }
        }

        // Эхо-кэш payment — отдельно от истории заказов, applyPrefillInput() сюда не
        // подходит: его is_prefilled означал бы «предзаполнено из истории», а это
        // восстановление другого рода (docs/plans/payment-section-echo-cache.md).
        $payment_echo = $this->session_storage->syncPaymentEcho();
        if ($payment_echo !== null) {
            $this->applyEchoToInput($params, ['payment' => $payment_echo]);
        }

        shopPrefillPluginDebug::recordEvent('echo', 'payment', [
            'result' => $payment_echo === null ? 'not_restored' : 'restored',
            'payment_id' => $payment_echo['id'] ?? null,
        ]);

        // Эхо-кэш группы доставки — по той же причине мимо applyPrefillInput()
        $delivery_echo = $this->session_storage->syncDeliveryEcho();
        $this->applyEchoToInput($params, $delivery_echo);
        shopPrefillPluginDebug::recordEvent('echo', 'delivery', [
            'result' => empty($delivery_echo) ? 'not_restored' : 'restored',
            'shipping_type_id' => $delivery_echo['shipping']['type_id'] ?? null,
            'shipping_id' => $delivery_echo['shipping']['id'] ?? null,
            'variant_id' => $delivery_echo['shipping']['variant_id'] ?? null,
        ]);
    }

    /**
     * Вливает восстановленные эхом секции в живой $data['input'] текущего processAll.
     *
     * Отдельно от applyPrefillInput(): тот выставляет is_prefilled, который означает
     * «предзаполнено из истории заказов», а восстановление после короткого замыкания —
     * другой род события и в статистику предзаполнения попадать не должно.
     *
     * @param array $params  Параметры хука
     * @param array $sections [section_id => значения секции]
     */
    private function applyEchoToInput(array &$params, array $sections): void
    {
        if (empty($sections) || !isset($params['data']['input'])) {
            return;
        }

        foreach ($sections as $section_id => $values) {
            $params['data']['input'][$section_id] = shopPrefillPluginHelper::deepMergeArrays(
                $params['data']['input'][$section_id] ?? [],
                $values
            );
        }
    }

    /**
     * Хук срабатывает при рендере секции авторизации на странице оформления заказа.
     * Добавляет JavaScript Zen Mode и рендерит блок управления для группы customer.
     *
     * Секция-носитель группы `customer` — см. shopPrefillPluginZenMode::GROUP_CARD_SECTION.
     * Перенос блока в другой хук требует правки той константы: от неё зависит, с какой
     * секции плагин снимает display:none ядра.
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию авторизации
     */
    public function handleCheckoutRenderAuth(array &$params): string
    {
        if (!$this->storefront_settings['active']) {
            return '';
        }

        $state = new shopPrefillCheckoutState($params);
        return $this->buildZenModeGroupBlock('customer', $state, 'checkoutRenderAuth')
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
        if (!$this->storefront_settings['active']) {
            return '';
        }

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
        if (!$this->storefront_settings['active']) {
            return '';
        }

        $state = new shopPrefillCheckoutState($params);
        return $this->renderSectionErrorsAndDebug($state, 'checkoutRenderShipping', 'SHIPPING SECTION');
    }

    /**
     * Хук срабатывает при рендере секции адреса доставки на странице оформления заказа.
     * Рендерит блок управления для Zen Mode группы delivery в конце секции.
     *
     * Секция-носитель группы `delivery` — см. shopPrefillPluginZenMode::GROUP_CARD_SECTION.
     * Перенос блока в другой хук требует правки той константы: от неё зависит, с какой
     * секции плагин снимает display:none ядра.
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию адреса
     */
    public function handleCheckoutRenderDetails(array &$params): string
    {
        if (!$this->storefront_settings['active']) {
            return '';
        }

        $state = new shopPrefillCheckoutState($params);
        return $this->buildZenModeGroupBlock('delivery', $state, 'checkoutRenderDetails')
            . $this->renderSectionErrorsAndDebug($state, 'checkoutRenderDetails', 'DETAILS SECTION');
    }

    /**
     * Хук срабатывает при рендере секции оплаты на странице оформления заказа.
     * Выводит блок управления для Zen Mode группы payment в конце секции.
     *
     * Секция-носитель группы `payment` — см. shopPrefillPluginZenMode::GROUP_CARD_SECTION.
     * Перенос блока в другой хук требует правки той константы: от неё зависит, с какой
     * секции плагин снимает display:none ядра.
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию оплаты
     */
    public function handleCheckoutRenderPayment(array &$params): string
    {
        if (!$this->storefront_settings['active']) {
            return '';
        }

        $state = new shopPrefillCheckoutState($params);
        return $this->buildZenModeGroupBlock('payment', $state, 'checkoutRenderPayment')
            . $this->renderSectionErrorsAndDebug($state, 'checkoutRenderPayment', 'PAYMENT SECTION');
    }

    /**
     * Хук срабатывает при рендере секции подтверждения на странице оформления заказа.
     * Показывает галочку согласия для гостей. CSS сворачивания групп сюда больше
     * не собирается — каждая группа несёт свой CSS вместе с кнопкой «Изменить»,
     * см. buildZenModeGroupBlock() и shopPrefillPluginZenMode::renderCollapseBlock().
     *
     * @param array $params Параметры хука
     * @return string HTML для вставки в секцию подтверждения
     */
    public function handleCheckoutRenderConfirm(array &$params): string
    {
        if (!$this->storefront_settings['active']) {
            return '';
        }

        $state = new shopPrefillCheckoutState($params);
        return $this->renderDeliveryUnavailableScript($state)
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
            $this->response->setCookie('prefill_user_selected', '', [
                'expires'  => -1,
                'path'     => '/',
                'samesite' => 'Lax',
            ]);
            return '';
        }

        if ($state->isFastRender()) {
            // Шаг shipping в этом ответе не считался (Shop-Script fast_render) — это не значит,
            // что вариант недоступен, просто расчёт ещё впереди. Куку не гасим: следующий
            // рендер (после fast_render всегда идёт фоновый calculate) досчитает по-настоящему.
            return '';
        }

        // Shipping не применим — сигнализируем JS; куку гасит JS при показе диалога
        return '<script>if(typeof $!=="undefined"){$(document).trigger("prefill_delivery_unavailable");}</script>';
    }

    /**
     * Строит блок Zen Mode для группы: синхронизация cookie + рендер.
     *
     * zenmode.css в это HTML больше не входит — подключается в frontend_head вместе
     * с остальными ассетами плагина (issue-74 §8), а не тегом <link> посреди <body>.
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
                shopPrefillPluginDebug::recordEvent('zen', $group, [
                    'collapsed' => false,
                    'reason' => $this->zen_mode->isActive() ? 'group_disabled' : 'zen_disabled',
                    'zen_active' => $this->zen_mode->isActive(),
                    'group_enabled' => $this->zen_mode->isGroupConfigured($group),
                    'rendered' => false,
                ]);
                return '';
            }
            $html = $this->zen_mode->buildCollapseBlock($group, $state);
            $decision = $this->zen_mode->getLastDecision();
            shopPrefillPluginDebug::recordEvent('zen', $group, array_merge($decision, [
                'rendered' => $html !== '',
                'shipping_id' => $group === 'delivery' ? $state->getShippingInstanceId() : null,
                'variant_id' => $group === 'delivery' ? $state->getShippingVariantId() : null,
                'payment_id' => $group === 'payment' ? $state->getPaymentId() : null,
            ]));
            return $html;
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Zen Mode error in ' . $log_context, [
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

            if (!$this->storefront_settings['prefill']['guest']['enabled']) {
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

        if ($this->is_debug_panel) {
            shopPrefillPluginDebug::recordEvent('render', $hook_name, [
                'fast_render' => $state->isFastRender(),
                'error_step_id' => $state->getErrorStepId(),
                'has_errors' => !empty($errors_info['has_errors']),
                'shipping_type_id' => $state->getShippingType(),
                'shipping_id' => $state->getShippingInstanceId(),
                'variant_id' => $state->getShippingVariantId(),
                'payment_id' => $state->getPaymentId(),
                'errors' => $errors_info,
            ]);
        }

        $errors_html = empty($errors_info['has_errors'])
            ? ''
            : shopPrefillPluginDebug::renderErrorsDebugHtml($errors_info, $section_label);
        return $errors_html . shopPrefillPluginDebug::renderPendingEvents();
    }

    /** @return string[] */
    private function findChangedPaths(array $before, array $after, string $prefix = ''): array
    {
        $paths = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $has_before = array_key_exists($key, $before);
            $has_after = array_key_exists($key, $after);
            if ($has_before && $has_after && is_array($before[$key]) && is_array($after[$key])) {
                $paths = array_merge($paths, $this->findChangedPaths($before[$key], $after[$key], $path));
            } elseif (!$has_before || !$has_after || $before[$key] !== $after[$key]) {
                $paths[] = $path;
            }
        }
        return array_slice($paths, 0, 200);
    }

    /** @return string[] */
    private function listLeafPaths(array $data, string $prefix = 'order'): array
    {
        $paths = [];
        foreach ($data as $key => $value) {
            $path = $prefix . '.' . $key;
            if (is_array($value)) {
                $paths = array_merge($paths, $this->listLeafPaths($value, $path));
            } else {
                $paths[] = $path;
            }
        }
        return array_slice($paths, 0, 200);
    }

}
