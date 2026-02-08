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
     * @param array $zen_settings Настройки zen из storefront settings
     */
    public function __construct(array $zen_settings)
    {
        $this->settings = $zen_settings;
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
     * Группа сворачивается если:
     * 1. Дзен-режим включен для этой группы
     * 2. Пользователь не развернул её вручную (нет cookie)
     *
     * @param string $group Имя группы
     * @return bool
     */
    public function shouldCollapseGroup(string $group): bool
    {
        if (!$this->isGroupEnabled($group)) {
            return false;
        }

        if ($this->isExpandedByUser($group)) {
            return false;
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
     * @return string HTML с тегом <style> или пустая строка
     */
    public function generateAllStyles(): string
    {
        if (!$this->isActive()) {
            return '';
        }

        $styles = [];

        foreach ($this->getGroups() as $group) {
            if ($this->shouldCollapseGroup($group)) {
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
            // Удаляем cookie
            document.cookie = cookieName + '=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
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

