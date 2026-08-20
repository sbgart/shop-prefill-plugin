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
     * @var shopPrefillPluginSessionStorageProvider Состояние заказа в сессии
     */
    private shopPrefillPluginSessionStorageProvider $session_storage;

    /**
     * @var shopPrefillPluginZenSummaryCache Последний удачный набор данных сводки
     */
    private shopPrefillPluginZenSummaryCache $summary_cache;

    /**
     * @param array $zen_settings Настройки zen из storefront settings
     * @param waResponse $response Response для записи cookies
     * @param waView $view View объект для рендеринга
     * @param shopPrefillPluginZenData $zen_data Данные для шаблонов сводки
     * @param waRequest $request Request для чтения cookies
     * @param shopPrefillPluginSessionStorageProvider $session_storage Состояние заказа
     * @param shopPrefillPluginZenSummaryCache $summary_cache Кэш данных сводки
     */
    public function __construct(
        array $zen_settings,
        waResponse $response,
        waView $view,
        shopPrefillPluginZenData $zen_data,
        waRequest $request,
        shopPrefillPluginSessionStorageProvider $session_storage,
        shopPrefillPluginZenSummaryCache $summary_cache
    ) {
        $this->settings        = $zen_settings;
        $this->response        = $response;
        $this->view            = $view;
        $this->zen_data        = $zen_data;
        $this->request         = $request;
        $this->session_storage = $session_storage;
        $this->summary_cache   = $summary_cache;
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
     * Определяет, нужно ли сворачивать группу.
     *
     * Три условия, и каждое читает свой источник — путать их нельзя:
     *   1. кука — покупатель сам открыл группу и работает в ней;
     *   2. ошибки — только из $params, они существуют в рамках запроса;
     *   3. минимум данных — только из сессии. $params описывает запрос, а не заказ:
     *      он пуст для всех секций ниже упавшего шага и при fast_render на каждой
     *      загрузке страницы. Спрашивать его «есть ли данные» бессмысленно.
     *
     * Любой разворот приводит к записи куки `expanded` в syncCollapseCookieState(),
     * поэтому группа, однажды открытая, не схлопнется под руками у покупателя,
     * который её как раз заполняет.
     *
     * См. docs/concept/RULES.md (R1–R3, Z1–Z5) и
     * docs/bugs/zen-collapse-on-upstream-checkout-error.md
     *
     * @param string $group Имя группы
     * @param shopPrefillCheckoutState $state Состояние текущего рендера
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

        if ($state->hasGroupErrors($group)) {
            shopPrefillPluginLog::debug("Zen group '{$group}' expanded: validation errors");
            return false;
        }

        if (! $this->isGroupMinimumFilled($group)) {
            shopPrefillPluginLog::debug("Zen group '{$group}' expanded: nothing to summarize yet");
            return false;
        }

        return true;
    }

    /**
     * Заполнен ли минимум группы по данным сессии.
     *
     * Ошибку чтения сессии трактуем как «заполнено»: молча развернуть все группы
     * из-за сбоя хранилища хуже, чем оставить дзен-режим работать как раньше.
     *
     * @param string $group Имя группы
     * @return bool
     */
    private function isGroupMinimumFilled(string $group): bool
    {
        try {
            return $this->session_storage->getSectionChecker()->isGroupMinimumFilled(
                $group,
                $this->session_storage->getCheckoutParams()
            );
        } catch (Exception $e) {
            shopPrefillPluginLog::warning('Failed reading checkout params for zen group check', [
                'group'   => $group,
                'message' => $e->getMessage(),
            ]);
            return true;
        }
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
     * Возвращает иконку для группы delivery или payment согласно настройке icon_source.
     *
     * icon_source: 'default' — дефолтный SVG группы (рендерится инлайново через спрайт,
     *              чтобы stroke="currentColor" наследовал цвет темы витрины, в т.ч. тёмной);
     *              'plugin'  — логотип активного плагина → fallback на дефолтный SVG;
     *              'custom'  — URL из поля icon.
     *
     * @param string $group Имя группы (delivery | payment)
     * @param shopPrefillCheckoutState $state Состояние чекаута
     * @return array{url: string, is_default: bool}
     */
    private function getPluginGroupIcon(string $group, shopPrefillCheckoutState $state): array
    {
        $source = $this->settings['groups'][$group]['icon_source'] ?? 'default';

        switch ($source) {
            case 'custom':
                return ['url' => $this->settings['groups'][$group]['icon'] ?? '', 'is_default' => false];
            case 'plugin':
                $logo = $this->getGroupPluginLogo($group, $state);
                if ($logo) {
                    return ['url' => $logo, 'is_default' => false];
                }
                return ['url' => '', 'is_default' => true];
            default: // 'default'
                return ['url' => '', 'is_default' => true];
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
     * Возвращает иконку для группы «Покупатель» согласно настройке icon_source.
     *
     * Дефолтная иконка рендерится инлайново через спрайт (см. getPluginGroupIcon()),
     * чтобы stroke="currentColor" наследовал цвет темы витрины, в т.ч. тёмной.
     *
     * @return array{url: string, is_default: bool}
     */
    private function getCustomerGroupIcon(): array
    {
        $source = $this->settings['groups']['customer']['icon_source'] ?? 'default';

        switch ($source) {
            case 'none':
                return ['url' => '', 'is_default' => false];
            case 'custom':
                return ['url' => $this->settings['groups']['customer']['icon'] ?? '', 'is_default' => false];
            case 'avatar':
                return $this->getContactAvatarIcon();
            default: // 'default'
                return ['url' => '', 'is_default' => true];
        }
    }

    /**
     * Возвращает иконку аватара текущего авторизованного покупателя.
     * Для гостей или при ошибке — fallback на стандартную иконку customer.
     *
     * @return array{url: string, is_default: bool}
     */
    private function getContactAvatarIcon(): array
    {
        $user = wa()->getUser();
        if (! $user || ! $user->isAuth()) {
            return ['url' => '', 'is_default' => true];
        }

        try {
            $contact = new waContact($user->getId());
            $url     = $contact->getPhoto(100, 100);
            if ($url) {
                return ['url' => $url, 'is_default' => false];
            }
            return ['url' => '', 'is_default' => true];
        } catch (waException $e) {
            return ['url' => '', 'is_default' => true];
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
     * Генерирует CSS, скрывающий содержимое секций группы (кроме .wa-plugin-hook).
     *
     * Вызывается только из renderCollapseBlock(), когда группа реально свёрнута —
     * так стиль не может уйти в разметку без кнопки «Изменить», которая его снимает
     * (см. issue-75: раньше CSS считался отдельно в confirm-хуке и не знал,
     * вывелась ли кнопка в своей секции).
     *
     * @param string $group Имя группы (customer, delivery, payment)
     * @return string HTML с тегом <style> или пустая строка
     */
    private function generateGroupStyles(string $group): string
    {
        if (! isset(self::GROUP_SECTIONS[$group])) {
            return '';
        }

        $css = [];
        foreach (self::GROUP_SECTIONS[$group] as $section) {
            $css[] = ".wa-step-{$section}-section .wa-section-body form > *:not(.wa-plugin-hook) { display: none !important; }";
        }

        return '<style id="prefill-zen-styles-' . $group . '">' . "\n" . implode("\n", $css) . "\n" . '</style>';
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
     * Скрывающий CSS группы выводится здесь же, вместе с кнопкой «Изменить» (только
     * когда $is_collapsed) — так CSS физически не может попасть в разметку без кнопки,
     * которая его снимает. Раньше CSS для всех групп собирался отдельно в confirm-хуке
     * по независимому расчёту и не знал, вывелся ли блок группы в своей секции (issue-75).
     *
     * @param string $group Имя группы
     * @param shopPrefillCheckoutState $state Данные чекаута
     * @param bool $is_collapsed Свёрнута ли группа
     * @return string HTML
     */
    public function renderCollapseBlock(string $group, shopPrefillCheckoutState $state, bool $is_collapsed = true): string
    {
        $icon_url        = null;
        $icon_is_default = false;
        $summary_html    = null;

        if ($is_collapsed) {
            // Иконка группы: только если глобальный режим не 'none'
            $icon_mode = $this->getIconDisplayMode();
            if ($icon_mode !== 'none') {
                if ($group === 'customer') {
                    // Для customer — собственная логика icon_source (default/none/custom/avatar)
                    $icon = $this->getCustomerGroupIcon();
                } else {
                    // Для delivery/payment — per-group icon_source (default/plugin/custom)
                    $icon = $this->getPluginGroupIcon($group, $state);
                }
                $icon_url        = $icon['url'] !== '' ? $icon['url'] : null;
                $icon_is_default = $icon['is_default'];
            }

            // Свёрнуто: сводка + кнопка "Изменить"
            $summary_html = $this->renderGroupSummary($group, $state);
        }

        $this->view->assign([
            'group'                           => $group,
            'is_collapsed'                    => $is_collapsed,
            'icon_url'                        => $icon_url,
            // Дефолтная иконка рендерится инлайново (спрайт + <use>), а не через <img src>,
            // иначе stroke="currentColor" не подхватывает цвет темы витрины (актуально для тёмной темы)
            'icon_is_default'                 => $icon_is_default,
            'icon_sprite_url'                 => $icon_is_default ? shopPrefillPlugin::getStaticUrl('img/zen/sprite.svg') : null,
            'summary_html'                    => $summary_html ?? null,
            'zen_toggle_button_extra_classes' => $this->settings['toggle_button_classes'] ?? '',
        ]);

        $template_path = shopPrefillPlugin::getPluginPath() . '/templates/zenmode/CollapseBlock.html';
        $html = $this->view->fetch('file:' . $template_path);

        return $is_collapsed ? ($this->generateGroupStyles($group) . $html) : $html;
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

        // $params пуст при коротком замыкании конвейера и при fast_render, а названия
        // тарифов и способов оплаты существуют только там. Удачный набор запоминаем,
        // пустой — достаём из кэша, иначе сводка выйдет наполовину пустой.
        if ($this->summary_cache->hasFreshData($group, $data)) {
            $this->summary_cache->set($group, $data);
        } else {
            $cached = $this->summary_cache->get($group);
            if (!empty($cached)) {
                $data = array_merge($data, $cached);
                shopPrefillPluginLog::debug("Zen summary for '{$group}' rendered from cache");
            }
        }

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

        // Проверяем, что в сводке остались смысловые символы (буквы/цифры), а не только
        // артефакты шаблона-разделителя вроде "•" — их trim()/strip_tags() не убирают
        if (preg_replace('/[^\p{L}\p{N}]/u', '', strip_tags($summary)) === '') {
            if ($group === 'customer') {
                return htmlspecialchars(_wp('zen.groups.customer.summary_empty'));
            }
            return '';
        }

        return $summary;
    }




    /**
     * Сбрасывает состояние дзен-режима после создания заказа: cookies групп и кэш сводки.
     * Иначе следующий заказ откроется с чужими названиями в свёрнутых блоках.
     */
    public function resetState(): void
    {
        $this->clearCookies();
        $this->summary_cache->clear();
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

