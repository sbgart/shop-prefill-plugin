<?php

/**
 * Хелпер для Zen-режима (сворачивание секций чекаута)
 *
 * Предоставляет методы для:
 * - Проверки состояния группы (включена/развернута)
 * - Генерации CSS для скрытия секций
 * - Рендеринга header и summary групп
 */
class shopPrefillPluginZenHelper
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
     * @var shopPrefillPlugin|null Плагин для доступа к extractCheckoutErrors
     */
    private ?shopPrefillPlugin $plugin = null;

    /**
     * @param array $zen_settings Настройки zen из storefront settings
     * @param shopPrefillPlugin|null $plugin Плагин для проверки ошибок
     */
    public function __construct(array $zen_settings, ?shopPrefillPlugin $plugin = null)
    {
        $this->settings = $zen_settings;
        $this->plugin = $plugin;
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
     */
    public function renderCollapseBlock(string $group, array $params, bool $is_collapsed = true): string
    {
        $output = '<div class="prefill-zen-collapse-block">';

        // Проверяем состояние cookie для обработки collapsing
        $cookie_state = waRequest::cookie(self::COOKIE_PREFIX . $group);

        // Smart Collapse: обработка попытки сворачивания
        if ($cookie_state === 'collapsing') {
            if ($is_collapsed) {
                // НЕТ ОШИБОК: удаляем cookie через inline JS
                $output .= '<script>document.cookie = "' . self::COOKIE_PREFIX . $group . '=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT";</script>';
            } else {
                // ЕСТЬ ОШИБКИ: показываем alert и переключаем cookie в expanded
                $output .= '<script>';
                $output .= 'alert("Пожалуйста, исправьте ошибки перед сворачиванием.");';
                $output .= 'document.cookie = "' . self::COOKIE_PREFIX . $group . '=expanded; path=/; SameSite=Lax";';
                $output .= '</script>';
            }
        }

        if ($is_collapsed) {
            // Свёрнуто: сводка + кнопка "Изменить"
            $summary = $this->renderGroupSummary($group, $params);
            if (!empty($summary)) {
                $output .= $summary;
            }
            $output .= '<a href="#" class="prefill-zen-btn js-prefill-zen-toggle" data-group="' . $group . '" data-action="expand">Изменить ▼</a>';
        } else {
            // Развёрнуто: только кнопка "Свернуть"
            $output .= '<a href="#" class="prefill-zen-btn js-prefill-zen-toggle" data-group="' . $group . '" data-action="collapse">Свернуть ▲</a>';
        }

        $output .= '</div>';
        return $output;
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

        // Если шаблон пустой — используем fallback
        if (empty($template)) {
            $fallback = $this->renderFallbackSummary($group, $data);
            if (empty(trim(strip_tags($fallback)))) {
                return '';
            }
            return '<div class="prefill-zen-summary">' . $fallback . '</div>';
        }

        // Используем существующий View из Webasyst (не создаём новый Smarty!)
        try {
            $view = wa()->getView();
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
            // В случае ошибки используем fallback
            $fallback = $this->renderFallbackSummary($group, $data);
            if (empty(trim(strip_tags($fallback)))) {
                return '';
            }
            return '<div class="prefill-zen-summary">' . $fallback . '</div>';
        }

        // Очищаем лишние пробелы и разделители
        $summary = preg_replace('/\s*•\s*$/', '', trim($summary));
        $summary = preg_replace('/^\s*•\s*/', '', $summary);
        $summary = preg_replace('/\s*•\s*•\s*/', ' • ', $summary);

        if (empty(trim(strip_tags($summary)))) {
            return '';
        }

        return '<div class="prefill-zen-summary">' . $summary . '</div>';
    }

    /**
     * Fallback-рендер сводки на основе данных
     *
     * @param string $group Имя группы
     * @param array $data Данные для подстановки
     * @return string
     */
    private function renderFallbackSummary(string $group, array $data): string
    {
        switch ($group) {
            case 'customer':
                $parts = [];
                if (!empty($data['company'])) {
                    $parts[] = htmlspecialchars($data['company']);
                }
                $name = trim(($data['firstname'] ?? '') . ' ' . ($data['lastname'] ?? ''));
                if (!empty($name)) {
                    $parts[] = htmlspecialchars($name);
                }
                if (!empty($data['phone'])) {
                    $parts[] = htmlspecialchars($data['phone']);
                }
                return implode(' • ', $parts);

            case 'delivery':
                $parts = [];
                if (!empty($data['shipping_name'])) {
                    $parts[] = htmlspecialchars($data['shipping_name']);
                }
                if (!empty($data['shipping_rate'])) {
                    $parts[] = htmlspecialchars($data['shipping_rate']);
                }
                $line1 = implode(' • ', $parts);
                $line2 = htmlspecialchars($data['city'] ?? '');
                return $line1 . (!empty($line2) ? '<br>' . $line2 : '');

            case 'payment':
                return htmlspecialchars($data['payment_name'] ?? '');

            default:
                return '';
        }
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

        // Данные чекаута находятся в $params['data']
        $checkout_data = $params['data'] ?? [];

        // Данные контакта из $params['contact']
        if (isset($params['contact'])) {
            $contact = $params['contact'];
            $data['firstname'] = $contact['firstname'] ?? '';
            $data['lastname'] = $contact['lastname'] ?? '';
            $data['phone'] = $contact['phone'] ?? '';
            $data['email'] = $contact['email'] ?? '';
            $data['company'] = $contact['company'] ?? '';
        }

        // Данные доставки из $params['data']['shipping']
        if (isset($checkout_data['shipping'])) {
            $shipping = $checkout_data['shipping'];
            $data['shipping_name'] = $shipping['name'] ?? '';
            $data['shipping_rate'] = isset($shipping['rate']) ? $this->formatPrice($shipping['rate']) : '';
        }

        // Данные региона/адреса из $params['shipping_address'] или $params['data']
        $shipping_address = $params['shipping_address'] ?? $checkout_data['shipping_address'] ?? [];
        if (!empty($shipping_address)) {
            $data['city'] = $shipping_address['city'] ?? '';
            $data['region'] = $shipping_address['region'] ?? '';
            $data['street'] = $shipping_address['street'] ?? '';
            $data['building'] = $shipping_address['building'] ?? '';
            $data['apartment'] = $shipping_address['apartment'] ?? '';
            $data['zip'] = $shipping_address['zip'] ?? '';
        }

        // Данные оплаты из $params['data']['payment']
        if (isset($checkout_data['payment'])) {
            $payment = $checkout_data['payment'];
            $data['payment_name'] = $payment['name'] ?? '';
        }

        return $data;
    }

    /**
     * Форматирует цену
     *
     * @param float|string $price Цена
     * @return string
     */
    private function formatPrice($price): string
    {
        if (empty($price) || $price == 0) {
            return 'Бесплатно';
        }
        return number_format((float) $price, 0, '', ' ') . ' ₽';
    }

    /**
     * Генерирует JavaScript для обработки кликов на кнопках toggle
     *
     * @return string HTML с тегом <script>
     */
    public function generateJavaScript(): string
    {
        if (!$this->isActive()) {
            return '';
        }

        return <<<'SCRIPT'
<script id="prefill-zen-js">
(function() {
    'use strict';

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.js-prefill-zen-toggle');
        if (!btn) return;

        e.preventDefault();

        var group = btn.dataset.group;
        var action = btn.dataset.action;
        var cookieName = 'prefill_zen_' + group;

        if (action === 'expand') {
            // Устанавливаем cookie и перезагружаем форму
            document.cookie = cookieName + '=expanded; path=/; SameSite=Lax';
        } else {
            // Smart Collapse: устанавливаем промежуточное состояние для проверки на сервере
            document.cookie = cookieName + '=collapsing; path=/; SameSite=Lax';
        }

        // Перезагружаем форму checkout
        if (window.waOrder && window.waOrder.form && window.waOrder.form.reload) {
            window.waOrder.form.reload();
        } else {
            location.reload();
        }
    });
})();
</script>
SCRIPT;
    }
}

