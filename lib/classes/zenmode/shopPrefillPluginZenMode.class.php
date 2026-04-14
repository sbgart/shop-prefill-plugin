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
        'payment'  => ['payment'],
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
     * @var waRequest Request для чтения cookies
     */
    private waRequest $request;

    /**
     * @var waResponse Response объект для записи cookies
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
     * @param waResponse $response Response для записи cookies
     * @param waView $view View объект для рендеринга
     * @param shopPrefillPluginZenData $zen_data Данные для шаблонов сводки
     * @param waRequest $request Request для чтения cookies
     */
    public function __construct(
        array $zen_settings,
        waResponse $response,
        waView $view,
        shopPrefillPluginZenData $zen_data,
        waRequest $request
    ) {
        $this->settings = $zen_settings;
        $this->response = $response;
        $this->view     = $view;
        $this->zen_data = $zen_data;
        $this->request  = $request;
    }

    /**
     * Проверяет, включен ли дзен-режим глобально
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return ! empty($this->settings['active']);
    }

    /**
     * Проверяет, нужно ли показывать иконки в свернутом состоянии.
     *
     * @return bool
     */
    public function shouldShowIcons(): bool
    {
        return $this->getIconDisplayMode() !== 'none';
    }

    /**
     * Режим отображения иконок: 'default' | 'plugin' | 'none'.
     *
     * @return string
     */
    private function getIconDisplayMode(): string
    {
        $mode = $this->settings['icon_display'] ?? 'plugin';
        return in_array($mode, ['default', 'plugin', 'none'], true) ? $mode : 'plugin';
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
            && ! empty($this->settings['groups'][$group]['enabled']);
    }



    /**
     * Определяет, нужно ли сворачивать группу
     *
     * Smart Collapse:
     * 1. Дзен-режим включен для этой группы
     * 2. Если expanded (пользователь развернул) → не сворачивать
     * 3. Иначе: сворачиваем только если нет ошибок в группе
     *
     * @param string $group Имя группы
     * @param array $params Данные чекаута для проверки ошибок
     * @return bool
     */
    public function shouldCollapseGroup(string $group, shopPrefillCheckoutState $state): bool
    {
        if (! $this->isGroupEnabled($group)) {
            return false;
        }

        $cookie_state = $this->request->cookie(self::COOKIE_PREFIX . $group);

        if ($cookie_state === 'expanded') {
            return false;
        }

        // Пусто или иное: сворачиваем только если нет ошибок в группе
        if ($state->hasGroupErrors($group)) {
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
     * Возвращает логотип активного плагина доставки/оплаты из данных чекаута.
     * Только для групп delivery и payment.
     *
     * @param string $group Имя группы (delivery | payment)
     * @param shopPrefillCheckoutState $state Состояние чекаута
     * @return string|null URL логотипа или null
     */
    private function getGroupPluginLogo(string $group, shopPrefillCheckoutState $state): ?string
    {
        if ($group === 'delivery') {
            return $state->getShippingLogoUrl();
        }
        if ($group === 'payment') {
            return $state->getPaymentLogoUrl();
        }
        return null;
    }

    /**
     * Возвращает URL иконки для группы delivery или payment согласно настройке icon_source.
     *
     * icon_source: 'default' — дефолтный SVG группы;
     *              'plugin'  — логотип активного плагина → fallback на дефолтный SVG;
     *              'custom'  — URL из поля icon.
     *
     * @param string $group Имя группы (delivery | payment)
     * @param shopPrefillCheckoutState $state Состояние чекаута
     * @return string URL иконки или пустая строка
     */
    private function getPluginGroupIcon(string $group, shopPrefillCheckoutState $state): string
    {
        $source = $this->settings['groups'][$group]['icon_source'] ?? 'default';

        switch ($source) {
            case 'custom':
                return $this->settings['groups'][$group]['icon'] ?? '';
            case 'plugin':
                $logo = $this->getGroupPluginLogo($group, $state);
                return $logo ?: shopPrefillPlugin::getStaticUrl("img/zen/{$group}.svg");
            default: // 'default'
                return shopPrefillPlugin::getStaticUrl("img/zen/{$group}.svg");
        }
    }

    /**
     * @deprecated Используй getPluginGroupIcon() для delivery/payment.
     * Оставлен для обратной совместимости.
     */
    public function getGroupIcon(string $group): string
    {
        $custom_icon = $this->settings['groups'][$group]['icon'] ?? '';
        if (! empty($custom_icon)) {
            return $custom_icon;
        }

        return shopPrefillPlugin::getStaticUrl("img/zen/{$group}.svg");
    }

    /**
     * Возвращает URL иконки для группы «Покупатель» согласно настройке icon_source.
     *
     * @return string URL иконки или пустая строка (без иконки)
     */
    private function getCustomerGroupIcon(): string
    {
        $source = $this->settings['groups']['customer']['icon_source'] ?? 'default';

        switch ($source) {
            case 'none':
                return '';
            case 'custom':
                return $this->settings['groups']['customer']['icon'] ?? '';
            case 'avatar':
                return $this->getContactAvatarUrl();
            default: // 'default'
                return shopPrefillPlugin::getStaticUrl('img/zen/customer.svg');
        }
    }

    /**
     * Возвращает URL аватара текущего авторизованного покупателя.
     * Для гостей или при ошибке — fallback на стандартную иконку customer.svg.
     *
     * @return string URL аватара или стандартной иконки
     */
    private function getContactAvatarUrl(): string
    {
        $user = wa()->getUser();
        if (! $user || ! $user->isAuth()) {
            return shopPrefillPlugin::getStaticUrl('img/zen/customer.svg');
        }

        try {
            $contact = new waContact($user->getId());
            $url     = $contact->getPhoto(100, 100);
            return $url ?: shopPrefillPlugin::getStaticUrl('img/zen/customer.svg');
        } catch (waException $e) {
            return shopPrefillPlugin::getStaticUrl('img/zen/customer.svg');
        }
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
    public function getGroupsToCollapse(shopPrefillCheckoutState $state): array
    {
        if (! $this->isActive()) {
            return [];
        }

        $result = [];
        foreach ($this->getGroups() as $group) {
            if ($this->shouldCollapseGroup($group, $state)) {
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
            if (! isset(self::GROUP_SECTIONS[$group])) {
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
     * Возвращает пер-инстансный шаблон, если он активен.
     * Возвращает null если запись отсутствует, active=false или template пустой.
     *
     * @param array  $group_settings Настройки группы (из getGroupSettings)
     * @param string $instance_id    ID инстанса плагина доставки или оплаты
     * @return string|null
     */
    private function resolveCustomTemplate(array $group_settings, string $instance_id): ?string
    {
        $entry = $group_settings['custom_templates'][$instance_id] ?? null;
        if (empty($entry) || empty($entry['active']) || empty($entry['template'])) {
            return null;
        }
        return $entry['template'];
    }

    /**
     * Возвращает шаблон сводки для группы.
     * Для delivery и payment сначала проверяет пер-инстансный шаблон с флагом active=true,
     * при его отсутствии — общий summary_template группы.
     *
     * @param string                    $group Имя группы (customer, delivery, payment)
     * @param shopPrefillCheckoutState  $state Состояние чекаута
     * @return string
     */
    public function getSummaryTemplate(string $group, shopPrefillCheckoutState $state): string
    {
        $group_settings = $this->getGroupSettings($group);

        if ($group === 'delivery') {
            $instance_id = $state->getShippingInstanceId();
        } elseif ($group === 'payment') {
            $instance_id = $state->getPaymentId();
        } else {
            $instance_id = null;
        }

        if ($instance_id) {
            $template = $this->resolveCustomTemplate($group_settings, $instance_id);
            if ($template !== null) {
                return $template;
            }
        }

        return $group_settings['summary_template'] ?? '';
    }




    /**
     * Синхронизирует cookie группы с фактическим состоянием при каждом обновлении формы.
     * При ошибках в секции бэкенд проставит 'expanded'; при сворачивании кука сбрасывается.
     *
     * @param string $group Имя группы
     * @param bool $is_collapsed Свёрнута ли группа (нет ошибок валидации)
     */
    protected function syncCollapseCookieState(string $group, bool $is_collapsed): void
    {
        if ($is_collapsed) {
            $this->response->setCookie(self::COOKIE_PREFIX . $group, '', -1, '/');
        } else {
            $this->response->setCookie(self::COOKIE_PREFIX . $group, 'expanded', 0, '/');
        }
    }

    /**
     * Определяет состояние группы, синхронизирует cookie и рендерит блок.
     * Публичный API для вывода блока (управление состоянием + рендер).
     *
     * @param string $group Имя группы
     * @param shopPrefillCheckoutState $state Состояние чекаута
     * @return string HTML
     */
    public function buildCollapseBlock(string $group, shopPrefillCheckoutState $state): string
    {
        $is_collapsed = $this->shouldCollapseGroup($group, $state);
        $this->syncCollapseCookieState($group, $is_collapsed);
        return $this->renderCollapseBlock($group, $state, $is_collapsed);
    }

    /**
     * Рендерит блок управления группой (только вывод HTML, без изменения cookie).
     *
     * @param string $group Имя группы
     * @param shopPrefillCheckoutState $state Данные чекаута
     * @param bool $is_collapsed Свёрнута ли группа
     * @return string HTML
     */
    public function renderCollapseBlock(string $group, shopPrefillCheckoutState $state, bool $is_collapsed = true): string
    {
        $icon_url     = null;
        $summary_html = null;

        if ($is_collapsed) {
            // Иконка группы: только если глобальный режим не 'none'
            $icon_mode = $this->getIconDisplayMode();
            if ($icon_mode !== 'none') {
                if ($group === 'customer') {
                    // Для customer — собственная логика icon_source (default/none/custom/avatar)
                    $icon_url = $this->getCustomerGroupIcon();
                } else {
                    // Для delivery/payment — per-group icon_source (default/plugin/custom)
                    $icon_url = $this->getPluginGroupIcon($group, $state);
                }
            }

            // Свёрнуто: сводка + кнопка "Изменить"
            $summary_html = $this->renderGroupSummary($group, $state);
        }

        $this->view->assign([
            'group'                           => $group,
            'is_collapsed'                    => $is_collapsed,
            'icon_url'                        => $icon_url ?? null,
            'summary_html'                    => $summary_html ?? null,
            'zen_toggle_button_extra_classes' => $this->settings['toggle_button_classes'] ?? '',
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
    public function renderGroupSummary(string $group, shopPrefillCheckoutState $state): string
    {
        $template = $this->getSummaryTemplate($group, $state);

        // Подготавливаем данные для подстановки через ZenData
        $data = $this->zen_data->extractSummaryData($group, $state);

        // Если шаблон пустой — ничего не выводим
        if (empty($template)) {
            return '';
        }

        // Используем существующий View из Webasyst (не создаём новый Smarty!)
        try {
            $view     = $this->view;
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
                if (! isset($old_vars[$key])) {
                    $view->clearAssign($key);
                }
            }
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Template rendering failed in shopPrefillPluginZenMode::renderGroupSummary', [
                'group'   => $group,
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
        // Куки: 'expanded' или отсутствовать
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

