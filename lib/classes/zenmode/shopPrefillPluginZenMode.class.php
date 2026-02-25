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
     * @var shopPrefillPluginZenData Объект для подготовки данных шаблонов
     */
    private shopPrefillPluginZenData $zen_data;

    /**
     * @param array $zen_settings Настройки zen из storefront settings
     * @param waResponse|null $response Response объект для cookies
     * @param waView|null $view View объект для рендеринга
     */
    public function __construct(
        array $zen_settings,
        ?waResponse $response = null,
        ?waView $view = null,
        ?shopPrefillPluginZenData $zen_data = null
    ) {
        $this->settings = $zen_settings;
        $this->response = $response ?? wa()->getResponse();
        $this->view = $view ?? wa()->getView();
        $this->zen_data = $zen_data ?? new shopPrefillPluginZenData($this->view);
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
     * Проверяет, нужно ли показывать иконки в свернутом состоянии
     *
     * @return bool
     */
    public function shouldShowIcons(): bool
    {
        return isset($this->settings['show_icons']) && !empty($this->settings['show_icons']);
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
     * Возвращает список групп, которые нужно визуально свернуть (скрыть содержимое).
     * Учитывает настройки, cookie и ошибки в данных чекаута.
     *
     * @param array $params Данные чекаута для проверки ошибок в группах
     * @return string[] Имена групп (customer, delivery, payment)
     */
    public function getGroupsToCollapse(array $params = []): array
    {
        if (!$this->isActive()) {
            return [];
        }

        $result = [];
        foreach ($this->getGroups() as $group) {
            if ($this->shouldCollapseGroup($group, $params)) {
                $result[] = $group;
            }
        }
        return $result;
    }

    /**
     * Генерирует CSS для переданных групп: скрывает содержимое секций (кроме .wa-plugin-hook).
     *
     * @param string[] $groups Имена групп (customer, delivery, payment)
     * @return string HTML с тегом <style> или пустая строка
     */
    public function generateAllStyles(array $groups = []): string
    {
        if (empty($groups)) {
            return '';
        }

        $styles = [];
        foreach ($groups as $group) {
            if (!isset(self::GROUP_SECTIONS[$group])) {
                continue;
            }
            $css = [];
            foreach (self::GROUP_SECTIONS[$group] as $section) {
                $css[] = ".wa-step-{$section}-section .wa-section-body form > *:not(.wa-plugin-hook) { display: none !important; }";
            }
            if ($css !== []) {
                $styles[] = "/* === GROUP: {$group} === */";
                $styles[] = implode("\n", $css);
            }
        }

        if (empty($styles)) {
            return '';
        }

        return '<style id="prefill-zen-styles">' . "\n" . implode("\n", $styles) . "\n" . '</style>';
    }

    // ==================== COLLAPSE BLOCK ====================


    /**
     * Возвращает шаблон сводки для группы
     *
     * @param string $group Имя группы
     * @param array $params Данные чекаута (нужны для определения типа доставки)
     * @return string
     */
    public function getSummaryTemplate(string $group, array $params = []): string
    {
        $group_settings = $this->getGroupSettings($group);

        // Для delivery пытаемся найти специфичный шаблон
        if ($group === 'delivery' && !empty($params)) {
            // Получаем ID выбранного инстанса доставки
            $shipping_id = $params['data']['shipping']['id'] ?? $params['vars']['shipping']['selected_variant']['id'] ?? null;

            // Если для данного инстанса задан кастомный шаблон, используем его
            if ($shipping_id && !empty($group_settings['custom_templates'][$shipping_id])) {
                return $group_settings['custom_templates'][$shipping_id];
            }
        }

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

        if ($is_collapsed) {
            // Иконка группы (если включена)
            if ($this->shouldShowIcons()) {
                $icon_url = $this->getGroupIcon($group);
            }

            // Свёрнуто: сводка + кнопка "Изменить"
            $summary_html = $this->renderGroupSummary($group, $params);
        }

        $this->view->assign([
            'group' => $group,
            'is_collapsed' => $is_collapsed,
            'icon_url' => $icon_url ?? null,
            'summary_html' => $summary_html ?? null,
        ]);

        $template_path = shopPrefillPlugin::getPluginPath() . '/templates/zenmode/CollapseBlock.html';
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
        $template = $this->getSummaryTemplate($group, $params);

        // Подготавливаем данные для подстановки через ZenData
        $data = $this->zen_data->extractSummaryData($group, $params);

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
                if (isset($view->getVars()[$key])) {
                    $old_vars[$key] = $view->getVars()[$key];
                }
            }

            // Присваиваем наши данные
            $view->assign($data);

            // Рендерим шаблон
            $summary = $view->fetch('string:' . $template);

            // Восстанавливаем оригинальные переменные
            $view->assign($old_vars);

            // Удаляем временные переменные, которых не было
            foreach ($data as $key => $value) {
                if (!isset($old_vars[$key])) {
                    $view->clearAssign($key);
                }
            }
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Template rendering failed in shopPrefillPluginZenMode::renderGroupSummary', [
                'group' => $group,
                'message' => $e->getMessage()
            ]);
            return '';
        }

        // Проверяем, что сводка не пустая после рендеринга
        if (empty(trim(strip_tags($summary)))) {
            return '';
        }

        return $summary;
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

        shopPrefillPluginLog::info('Zen Mode cookies cleared after order creation');
    }

}

