<?php

/**
 * Координатор Zen-режима (сворачивание секций чекаута)
 *
 * Предоставляет методы для:
 * - Проверки состояния группы (включена/развернута)
 * - Генерации CSS для скрытия секций
 * - Рендеринга header и summary групп
 */
class shopPrefillPluginZenMode
{
    /**
     * Маппинг группа → секции чекаута
     */
    const GROUP_SECTIONS = [
        'customer' => ['auth'],
        'delivery' => ['region', 'shipping', 'details'],
        'payment' => ['payment'],
    ];

    /**
     * Имена cookies для хранения состояния
     */
    const COOKIE_PREFIX = 'prefill_zen_';

    /**
     * @var array Настройки zen из storefront settings
     */
    private array $settings;

    /**
     * @var waResponse Response объект для работы с cookies
     */
    private waResponse $response;

    /**
     * @var waView View объект для рендеринга шаблонов
     */
    private waView $view;

    /**
     * @var string Валюта магазина (кешируется для оптимизации)
     */
    private string $currency;

    /**
     * @param array $zen_settings Настройки zen из storefront settings
     * @param waResponse|null $response Response объект для cookies
     * @param waView|null $view View объект для рендеринга
     */
    public function __construct(
        array $zen_settings,
        ?waResponse $response = null,
        ?waView $view = null
    ) {
        $this->settings = $zen_settings;
        $this->response = $response ?? wa()->getResponse();
        $this->view = $view ?? wa()->getView();
        $this->currency = wa('shop')->getConfig()->getCurrency();
    }

    /**
     * Проверяет, включен ли дзен-режим глобально
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return !empty($this->settings['active']);
    }

    /**
     * Проверяет, включен ли дзен-режим для группы
     *
     * @param string $group Имя группы (customer, delivery, payment)
     * @return bool
     */
    public function isGroupEnabled(string $group): bool
    {
        return $this->isActive()
            && isset($this->settings['groups'][$group])
            && !empty($this->settings['groups'][$group]['enabled']);
    }

    /**
     * Проверяет, развернута ли группа пользователем (по cookie)
     *
     * @param string $group Имя группы
     * @return bool
     */
    public function isExpandedByUser(string $group): bool
    {
        $cookie_name = self::COOKIE_PREFIX . $group;
        return waRequest::cookie($cookie_name) === 'expanded';
    }

    /**
     * Определяет, нужно ли сворачивать группу
     *
     * Smart Collapse:
     * 1. Дзен-режим включен для этой группы
     * 2. Обработка состояния collapsing (попытка свернуть):
     *    - Есть ошибки В ГРУППЕ → не сворачивать (alert будет показан)
     *    - Нет ошибок → свернуть (cookie будет удалена)
     * 3. Если expanded пользователем → не сворачивать
     * 4. Дефолт: проверка ошибок В ГРУППЕ
     *
     * @param string $group Имя группы
     * @param array $params Данные чекаута для проверки ошибок
     * @return bool
     */
    public function shouldCollapseGroup(string $group, array $params = []): bool
    {
        if (!$this->isGroupEnabled($group)) {
            return false;
        }

        $cookie_state = waRequest::cookie(self::COOKIE_PREFIX . $group);

        // 1. Обработка попытки сворачивания (COLLAPSING)
        if ($cookie_state === 'collapsing') {
            if (!empty($params)) {
                $errors = $this->extractGroupErrors($params, $group);

                if ($errors['has_errors']) {
                    // ЕСТЬ ОШИБКИ В ГРУППЕ: не сворачивать, alert будет показан в renderCollapseBlock
                    return false;
                } else {
                    // НЕТ ОШИБОК: свернуть, cookie будет удалена в renderCollapseBlock
                    return true;
                }
            }
        }

        // 2. Развернуто пользователем — не сворачиваем
        if ($cookie_state === 'expanded') {
            return false;
        }

        // 3. Дефолтное поведение: проверяем ошибки В ГРУППЕ
        if (!empty($params)) {
            $errors_info = $this->extractGroupErrors($params, $group);
            if ($errors_info['has_errors']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Возвращает список секций для группы
     *
     * @param string $group Имя группы
     * @return array
     */
    public function getGroupSections(string $group): array
    {
        return self::GROUP_SECTIONS[$group] ?? [];
    }

    /**
     * Возвращает настройки группы
     *
     * @param string $group Имя группы
     * @return array
     */
    public function getGroupSettings(string $group): array
    {
        return $this->settings['groups'][$group] ?? [];
    }

    /**
     * Извлекает ошибки для конкретной группы секций
     *
     * Маппинг групп → секций:
     * - customer → auth (включая service_agreement)
     * - delivery → region, shipping, details
     * - payment → payment
     *
     * @param array $params Массив параметров из checkout хука
     * @param string $group Имя группы (customer, delivery, payment)
     * @return array Структурированный массив с информацией об ошибках группы
     */
    private function extractGroupErrors(array $params, string $group): array
    {
        $has_errors = false;
        $group_errors = [];

        switch ($group) {
            case 'customer':
                // Проверяем ошибки в auth
                $auth_delayed_errors = ifset($params, 'data', 'auth', 'delayed_errors', []);
                if (!empty($auth_delayed_errors)) {
                    $has_errors = true;
                    $group_errors['auth_delayed_errors'] = $auth_delayed_errors;
                }

                // Проверяем service_agreement
                $service_agreement_value = ifset($params, 'vars', 'auth', 'service_agreement', null);
                if ($service_agreement_value !== null && $service_agreement_value == 0) {
                    $has_errors = true;
                    $group_errors['service_agreement_error'] = true;
                }

                // Проверяем regular_errors если error_step_id = 'auth'
                $error_step_id = ifset($params, 'error_step_id', null);
                if ($error_step_id === 'auth') {
                    $regular_errors = ifset($params, 'errors', []);
                    if (!empty($regular_errors)) {
                        $has_errors = true;
                        $group_errors['regular_errors'] = $regular_errors;
                    }
                }
                break;

            case 'delivery':
                // Проверяем ошибки в region, shipping, details
                $details_delayed_errors = ifset($params, 'data', 'details', 'delayed_errors', []);
                if (!empty($details_delayed_errors)) {
                    $has_errors = true;
                    $group_errors['details_delayed_errors'] = $details_delayed_errors;
                }

                // Проверяем regular_errors если error_step_id в группе delivery
                $error_step_id = ifset($params, 'error_step_id', null);
                if (in_array($error_step_id, ['region', 'shipping', 'details'])) {
                    $regular_errors = ifset($params, 'errors', []);
                    if (!empty($regular_errors)) {
                        $has_errors = true;
                        $group_errors['regular_errors'] = $regular_errors;
                    }
                }
                break;

            case 'payment':
                // Проверяем ошибки в payment
                $error_step_id = ifset($params, 'error_step_id', null);
                if ($error_step_id === 'payment') {
                    $regular_errors = ifset($params, 'errors', []);
                    if (!empty($regular_errors)) {
                        $has_errors = true;
                        $group_errors['regular_errors'] = $regular_errors;
                    }
                }
                break;
        }

        return [
            'has_errors' => $has_errors,
            'group' => $group,
            'errors' => $group_errors,
        ];
    }

    /**
     * Возвращает путь к иконке группы
     *
     * @param string $group Имя группы
     * @return string URL иконки
     * @throws waException
     */
    public function getGroupIcon(string $group): string
    {
        $custom_icon = $this->settings['groups'][$group]['icon'] ?? '';
        if (!empty($custom_icon)) {
            return $custom_icon;
        }

        // Стандартная иконка плагина
        return shopPrefillPlugin::getStaticUrl("img/zen/{$group}.svg");
    }

    /**
     * Возвращает все доступные группы
     *
     * @return array
     */
    public function getGroups(): array
    {
        return array_keys(self::GROUP_SECTIONS);
    }

    // ==================== CSS GENERATION ====================

    /**
     * Генерирует CSS для скрытия секций одной группы
     *
     * Скрывает только содержимое формы, кроме plugin hooks (.wa-plugin-hook).
     * Header секции остается видимым.
     *
     * @param string $group Имя группы
     * @return string CSS-правила
     */
    public function generateGroupStyles(string $group): string
    {
        if (!isset(self::GROUP_SECTIONS[$group])) {
            return '';
        }

        $sections = self::GROUP_SECTIONS[$group];
        $css = [];

        foreach ($sections as $section) {
            // Скрываем содержимое формы, кроме plugin hooks
            $css[] = ".wa-step-{$section}-section .wa-section-body form > *:not(.wa-plugin-hook) { display: none !important; }";
        }

        return implode("\n", $css);
    }

    /**
     * Генерирует ОДИН блок CSS для всех активных групп
     *
     * Вызывается в первом хуке (checkoutRenderAuth) и генерирует
     * единый <style> тег для всех групп, которые нужно свернуть.
     *
     * @param array $params Данные чекаута для проверки ошибок
     * @return string HTML с тегом <style> или пустая строка
     */
    public function generateAllStyles(array $params = []): string
    {
        if (!$this->isActive()) {
            return '';
        }

        $styles = [];

        foreach ($this->getGroups() as $group) {
            if ($this->shouldCollapseGroup($group, $params)) {
                $styles[] = "/* === GROUP: {$group} === */";
                $styles[] = $this->generateGroupStyles($group);
            }
        }

        if (empty($styles)) {
            return '';
        }

        return '<style id="prefill-zen-styles">' . "\n" . implode("\n", $styles) . "\n" . '</style>';
    }

    // ==================== COLLAPSE BLOCK ====================

    /**
     * Локализованные имена групп
     */
    const GROUP_NAMES = [
        'customer' => 'Покупатель',
        'delivery' => 'Доставка',
        'payment' => 'Оплата',
    ];

    /**
     * Возвращает локализованное имя группы
     *
     * @param string $group Имя группы
     * @return string
     */
    public function getGroupName(string $group): string
    {
        // TODO: использовать _wp() для локализации
        return self::GROUP_NAMES[$group] ?? ucfirst($group);
    }

    /**
     * Возвращает шаблон сводки для группы
     *
     * @param string $group Имя группы
     * @return string
     */
    public function getSummaryTemplate(string $group): string
    {
        $group_settings = $this->getGroupSettings($group);
        return $group_settings['summary_template'] ?? '';
    }

    /**
     * Рендерит блок управления группой с кнопкой toggle и сводкой
     *
     * @param string $group Имя группы
     * @param array $params Данные чекаута
     * @param bool $is_collapsed Свёрнута ли группа
     * @return string HTML
     * @throws waException
     */
    public function renderCollapseBlock(string $group, array $params, bool $is_collapsed = true): string
    {
        $output = '<div class="prefill-zen-collapse-block">';

        // Проверяем состояние cookie для обработки collapsing
        $cookie_state = waRequest::cookie(self::COOKIE_PREFIX . $group);

        // Smart Collapse: обработка попытки сворачивания
        if ($cookie_state === 'collapsing') {
            if ($is_collapsed) {
                // НЕТ ОШИБОК: удаляем cookie через PHP
                $this->response->setCookie(
                    self::COOKIE_PREFIX . $group,
                    '',
                    -1,  // удаление
                    '/'
                );
            } else {
                // ЕСТЬ ОШИБКИ (PHP нашёл при race condition): возвращаем expanded
                $this->response->setCookie(
                    self::COOKIE_PREFIX . $group,
                    'expanded',
                    0,  // session cookie
                    '/'
                );
                // Флаг prefillZenTriggerValidation больше не нужен — валидация была в JS
            }
        }

        // ФИКСАЦИЯ СОСТОЯНИЯ: если секция развёрнута и куки нет → устанавливаем expanded
        // Это предотвращает случайное сворачивание при reload формы
        if (!$is_collapsed && $cookie_state === null) {
            $this->response->setCookie(
                self::COOKIE_PREFIX . $group,
                'expanded',
                0,  // session cookie
                '/'
            );
        }

        if ($is_collapsed) {
            // Свёрнуто: сводка + кнопка "Изменить"
            $summary = $this->renderGroupSummary($group, $params);
            if (!empty($summary)) {
                $output .= $summary;
            }
        }

        // Рендерим кнопку через шаблон
        $output .= $this->renderToggleButton($group, $is_collapsed);

        $output .= '</div>';
        return $output;
    }

    /**
     * Рендерит кнопку toggle через шаблон
     *
     * @param string $group Имя группы
     * @param bool $is_collapsed Состояние группы
     * @return string HTML
     * @throws waException
     */
    private function renderToggleButton(string $group, bool $is_collapsed): string
    {
        $this->view->assign([
            'group' => $group,
            'is_collapsed' => $is_collapsed,
        ]);

        $template_path = shopPrefillPlugin::getPluginPath() . '/templates/zenmode/ToggleButton.html';
        return $this->view->fetch('file:' . $template_path);
    }

    /**
     * Рендерит сводку данных для группы
     *
     * @param string $group Имя группы
     * @param array $params Данные чекаута
     * @return string HTML
     */
    public function renderGroupSummary(string $group, array $params): string
    {
        $template = $this->getSummaryTemplate($group);

        // Подготавливаем данные для подстановки
        $data = $this->extractSummaryData($group, $params);

        // Если шаблон пустой — ничего не выводим
        if (empty($template)) {
            return '';
        }

        // Используем существующий View из Webasyst (не создаём новый Smarty!)
        try {
            $view = $this->view;
            $old_vars = [];

            // Сохраняем существующие переменные с теми же именами
            foreach ($data as $key => $value) {
                if (isset($view->tpl_vars[$key])) {
                    $old_vars[$key] = $view->tpl_vars[$key];
                }
            }

            // Присваиваем наши данные
            $view->assign($data);

            // Рендерим шаблон
            $summary = $view->fetch('string:' . $template);

            // Восстанавливаем оригинальные переменные
            foreach ($old_vars as $key => $value) {
                $view->tpl_vars[$key] = $value;
            }

            // Удаляем временные переменные, которых не было
            foreach ($data as $key => $value) {
                if (!isset($old_vars[$key])) {
                    unset($view->tpl_vars[$key]);
                }
            }
        } catch (Exception $e) {
            return '';
        }

        // Проверяем, что сводка не пустая после рендеринга
        if (empty(trim(strip_tags($summary)))) {
            return '';
        }

        return '<div class="prefill-zen-summary">' . $summary . '</div>';
    }


    /**
     * Извлекает данные для сводки из params чекаута
     *
     * @param string $group Имя группы
     * @param array $params Данные чекаута
     * @return array
     */
    private function extractSummaryData(string $group, array $params): array
    {
        // Инициализируем все ключи по умолчанию
        $data = [
            'firstname' => '',
            'lastname' => '',
            'phone' => '',
            'email' => '',
            'company' => '',
            'shipping_name' => '',
            'shipping_rate' => '',
            'city' => '',
            'region' => '',
            'street' => '',
            'building' => '',
            'apartment' => '',
            'zip' => '',
            'payment_name' => '',
        ];

        // === ДАННЫЕ КОНТАКТА ===
        // Приоритет: vars → input
        $auth_fields = $params['vars']['auth']['fields'] ?? [];
        $auth_input = $params['data']['input']['auth']['data'] ?? [];

        $data['firstname'] = $auth_fields['firstname']['value'] ?? $auth_input['firstname'] ?? '';
        $data['lastname'] = $auth_fields['lastname']['value'] ?? $auth_input['lastname'] ?? '';
        $data['phone'] = $auth_fields['phone']['value'] ?? $auth_input['phone'] ?? '';
        $data['email'] = $auth_fields['email']['value'] ?? $auth_input['email'] ?? '';
        $data['company'] = $auth_fields['company']['value'] ?? $auth_input['company'] ?? '';

        // === ДАННЫЕ ДОСТАВКИ ===
        // Приоритет: data.shipping.selected_variant → vars.shipping.shipping_rate
        $selected_variant = $params['data']['shipping']['selected_variant'] ?? [];
        $shipping_rate_data = $params['vars']['shipping']['shipping_rate'] ?? [];

        $data['shipping_name'] = $selected_variant['name'] ?? $shipping_rate_data['name'] ?? '';
        $shipping_rate_raw = $selected_variant['rate'] ?? $shipping_rate_data['rate'] ?? null;
        $data['shipping_rate'] = $shipping_rate_raw !== null ? $this->formatPrice($shipping_rate_raw) : '';

        // === ДАННЫЕ АДРЕСА ===
        // Приоритет: data.shipping.address → input.region → vars.region.selected_values
        $shipping_address = $params['data']['shipping']['address'] ?? [];
        $region_input = $params['data']['input']['region'] ?? [];
        $region_selected = $params['vars']['region']['selected_values'] ?? [];
        $details_address = $params['data']['input']['details']['shipping_address'] ?? [];

        $data['city'] = $shipping_address['city'] ?? $region_input['city'] ?? $region_selected['city'] ?? '';
        $data['region'] = $shipping_address['region'] ?? $region_input['region'] ?? $region_selected['region_id'] ?? '';
        $data['zip'] = $shipping_address['zip'] ?? $region_input['zip'] ?? $region_selected['zip'] ?? '';
        $data['street'] = $shipping_address['street'] ?? $details_address['street'] ?? '';
        $data['building'] = $shipping_address['building'] ?? $details_address['building'] ?? '';
        $data['apartment'] = $shipping_address['apartment'] ?? $details_address['apartment'] ?? '';

        // === ДАННЫЕ ОПЛАТЫ ===
        // Извлекаем ID оплаты и находим название в methods
        $payment_id = $params['data']['payment']['id'] ?? '';
        if (!empty($payment_id)) {
            $payment_methods = $params['vars']['payment']['methods'] ?? [];
            if (isset($payment_methods[$payment_id])) {
                $data['payment_name'] = $payment_methods[$payment_id]['name'] ?? '';
            }
        }

        return $data;
    }

    /**
     * Форматирует цену для отображения в сводке
     *
     * Использует API Webasyst для форматирования с учётом валюты магазина.
     * HTML-классы для стилизации:
     * - <span class="prefill-zen-price-free"> - для "Бесплатно"
     * - <span class="prefill-zen-price"> - для обычной цены
     *
     * @param float|string $price Цена
     * @return string HTML с форматированной ценой
     */
    private function formatPrice($price): string
    {
        if (empty($price) || $price == 0) {
            $free_text = _wp('zen.price.free');
            return '<span class="prefill-zen-price-free">' . htmlspecialchars($free_text) . '</span>';
        }
        // Используем wa_currency_html() для правильного форматирования с учётом валюты
        // %t - убирает trailing zeros (350.00 → 350)
        // {h} - использует HTML-версию знака валюты
        $formatted = wa_currency_html($price, $this->currency, '%t{h}');
        return '<span class="prefill-zen-price">' . $formatted . '</span>';
    }

    /**
     * Очищает cookies состояния всех групп Zen Mode
     * Вызывается после создания заказа для сброса состояния форм
     */
    public function clearCookies(): void
    {
        // Очищаем куки для всех групп (customer, delivery, payment)
        // Куки могут содержать 'expanded', 'collapsing' или отсутствовать
        foreach (array_keys(self::GROUP_SECTIONS) as $group) {
            $this->response->setCookie(
                self::COOKIE_PREFIX . $group,
                '',
                -1,  // отрицательное время = удаление
                '/'
            );
        }
    }

}

