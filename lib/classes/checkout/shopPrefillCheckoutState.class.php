<?php

/**
 * Адаптер над массивом $params checkout-хука Webasyst.
 *
 * Инкапсулирует всю логику поиска данных в многомерном $params:
 * двойные ??, ifset(), приоритеты источников.
 *
 * Предоставляет строгие типизированные геттеры и единственный сеттер — applyPrefillInput().
 *
 * Конструктор принимает $params по ссылке, потому что applyPrefillInput()
 * должен менять исходный массив, который Webasyst продолжает использовать в processAll().
 */
class shopPrefillCheckoutState
{
    private array $params;
    private bool $is_prefilled = false;

    /**
     * @param array $params Массив параметров из checkout-хука (передаётся по ссылке)
     */
    public function __construct(array &$params)
    {
        $this->params = &$params;
    }

    // -------------------------------------------------------------------------
    // Контакт
    // -------------------------------------------------------------------------

    /**
     * Возвращает имя покупателя.
     * Приоритет: vars.auth.fields → data.input.auth.data
     */
    public function getFirstName(): string
    {
        return self::scalarString(
            $this->params['vars']['auth']['fields']['firstname']['value']
                ?? $this->params['data']['input']['auth']['data']['firstname']
                ?? ''
        );
    }

    /**
     * Возвращает фамилию покупателя.
     */
    public function getLastName(): string
    {
        return self::scalarString(
            $this->params['vars']['auth']['fields']['lastname']['value']
                ?? $this->params['data']['input']['auth']['data']['lastname']
                ?? ''
        );
    }

    /**
     * Возвращает телефон покупателя.
     */
    public function getPhone(): string
    {
        return self::scalarString(
            $this->params['vars']['auth']['fields']['phone']['value']
                ?? $this->params['data']['input']['auth']['data']['phone']
                ?? ''
        );
    }

    /**
     * Возвращает email покупателя.
     */
    public function getEmail(): string
    {
        return self::scalarString(
            $this->params['vars']['auth']['fields']['email']['value']
                ?? $this->params['data']['input']['auth']['data']['email']
                ?? ''
        );
    }

    /**
     * Есть ли у группы `customer` что показать в свёрнутом виде.
     *
     * Предикат Z2 для этой группы: спрашивает **источник сводки**, а не сессию чекаута.
     * Причина — в сессию `order.auth.data.*` для авторизованного покупателя не пишутся
     * вовсе: предзаполнение auth-секцию для него сознательно пропускает
     * (`prepareAuthSectionParams()`, «данные берутся из контакта автоматически»), а из POST
     * они приходят только после первого рендера. Проверка по сессии отказывала на первом
     * кадре каждой новой сессии, хотя поля формы уже были заполнены.
     * См. docs/bugs/zen-customer-group-never-collapses-f01.md и
     * docs/plans/zen-customer-gate-source-alignment.md.
     *
     * Ветки по авторизации нет намеренно: у гостя те же поля приходят пустыми, поэтому один
     * предикат даёт верный ответ обоим. Так гейт и сводка читают один и тот же массив, и
     * разойтись — «свёрнуто, а в сводке пусто» — структурно не могут.
     *
     * Набор полей тот же, что у минимума секции `auth` в SectionChecker
     * (SECTION_DATA_FIELDS): имя, телефон, email. `lastname` и `company` в сводке участвуют,
     * но в минимум не входят и здесь — расширение набора меняло бы Z4 отдельно от источника.
     *
     * @return bool
     */
    public function hasCustomerIdentityData(): bool
    {
        return $this->getFirstName() !== ''
            || $this->getPhone() !== ''
            || $this->getEmail() !== '';
    }

    /**
     * Возвращает компанию покупателя.
     */
    public function getCompany(): string
    {
        return self::scalarString(
            $this->params['vars']['auth']['fields']['company']['value']
                ?? $this->params['data']['input']['auth']['data']['company']
                ?? ''
        );
    }

    /**
     * Возвращает значение чекбокса «Согласие на обработку персональных данных» (auth[service_agreement]).
     * Для вывода в саммари Zen Mode. 1 = согласен, 0 = не согласен, '' = не задано/чекбокс отключён.
     *
     * @return int|string 1, 0 или ''
     */
    public function getServiceAgreement()
    {
        $value = $this->params['vars']['auth']['service_agreement'] ?? null;
        if ($value === null || $value === '') {
            return '';
        }
        return $value === 1 || $value === '1' ? 1 : 0;
    }

    /**
     * Возвращает текст подсказки согласия на обработку персональных данных (data[customer][service_agreement_hint]).
     * Для вывода в саммари Zen Mode. Приоритет: data.customer → vars.config → shopCheckoutConfig.
     *
     * @return string
     */
    public function getServiceAgreementHint(): string
    {
        $hint = $this->params['data']['customer']['service_agreement_hint'] ?? null;
        if ($hint !== null && $hint !== '') {
            return is_string($hint) ? $hint : '';
        }

        $config = $this->params['vars']['config'] ?? null;
        if ($config === null && class_exists('shopCheckoutConfig')) {
            try {
                $config = new shopCheckoutConfig(true);
            } catch (Exception $e) {
                return '';
            }
        }
        $hint = $config['customer']['service_agreement_hint'] ?? '';
        return is_string($hint) ? $hint : '';
    }

    /**
     * Возвращает заголовок блока секции, как он задан в настройках чекаута витрины
     * («Оплата», «Способ оплаты», что угодно — поле block_name, своё у каждой витрины).
     *
     * Тот же источник, из которого его берёт ядро: payment.html выводит
     * `{$_config.block_name}`, где `$_config = $config.payment`. Хардкодить нельзя —
     * заголовок настраиваемый, и на витрине с переименованным блоком плагин показал бы
     * не то, что показывает ядро в обычном рендере.
     *
     * @param string $section ID секции (payment, region, auth, …)
     * @return string Заголовок или '' если задать не удалось
     */
    public function getSectionBlockName(string $section): string
    {
        $config = $this->params['vars']['config'] ?? null;
        if ($config === null && class_exists('shopCheckoutConfig')) {
            try {
                $config = new shopCheckoutConfig(true);
            } catch (Exception $e) {
                return '';
            }
        }

        $name = $config[$section]['block_name'] ?? '';
        return is_string($name) ? $name : '';
    }

    /**
     * Возвращает кастомные поля контакта (всё, кроме стандартных).
     *
     * @return array<string, mixed>
     */
    public function getCustomContactFields(): array
    {
        $standard_fields = ['firstname', 'lastname', 'phone', 'email', 'company', 'password', 'confirm_password'];

        $auth_input = self::arrayValue($this->params['data']['input']['auth']['data'] ?? []);
        $auth_fields = self::arrayValue($this->params['vars']['auth']['fields'] ?? []);

        $custom = [];

        foreach ($auth_input as $key => $value) {
            if (!in_array($key, $standard_fields, true)) {
                $custom[$key] = $value;
            }
        }

        foreach ($auth_fields as $key => $field_data) {
            if (!in_array($key, $standard_fields, true) && !isset($custom[$key])) {
                $custom[$key] = $field_data['value'] ?? '';
            }
        }

        return $custom;
    }

    // -------------------------------------------------------------------------
    // Доставка — вариант
    // -------------------------------------------------------------------------

    /**
     * Возвращает сырой массив выбранного варианта доставки.
     * Приоритет: data.shipping.selected_variant → vars.shipping.shipping_rate
     *
     * @return array<string, mixed>
     */
    public function getSelectedVariant(): array
    {
        return self::arrayValue(
            $this->params['data']['shipping']['selected_variant']
                ?? $this->params['vars']['shipping']['shipping_rate']
                ?? []
        );
    }

    /**
     * Возвращает variant_id выбранного тарифа (формат "plugin_id.method_id").
     */
    public function getShippingVariantId(): ?string
    {
        $variant = $this->getSelectedVariant();
        $variant_id = self::scalarString($variant['variant_id'] ?? null);
        return $variant_id !== '' ? $variant_id : null;
    }

    /**
     * Возвращает ID плагина/службы доставки (первая часть variant_id до точки).
     */
    public function getShippingServiceId(): ?string
    {
        $variant_id = $this->getShippingVariantId();
        if ($variant_id === null) {
            return null;
        }
        $parts = explode('.', $variant_id);
        return $parts[0] !== '' ? $parts[0] : null;
    }

    /**
     * Возвращает ID инстанса способа доставки в смысле shop_plugin.id (первая часть variant_id до точки).
     *
     * В ядре: shopCheckoutDetailsStep после успешного расчёта выставляет data.shipping.id = первая часть
     * selected_variant.variant_id; до шага details этого ключа нет. В vars результата шага shipping лежит
     * selected_variant_id (строка), а не вложенный selected_variant — см. shopCheckoutShippingStep::process().
     */
    public function getShippingInstanceId(): ?string
    {
        $id = self::scalarString($this->params['data']['shipping']['id'] ?? null);
        if ($id !== '') {
            return $id;
        }

        $explicit = self::scalarString($this->params['data']['shipping']['selected_variant']['id'] ?? null);
        if ($explicit !== '') {
            return $explicit;
        }

        $from_variant = $this->getShippingServiceId();
        if ($from_variant !== null) {
            return $from_variant;
        }

        $selected_variant_id = self::scalarString($this->params['vars']['shipping']['selected_variant_id'] ?? null);
        if ($selected_variant_id !== '') {
            $parts = explode('.', $selected_variant_id, 2);

            return $parts[0] !== '' ? $parts[0] : null;
        }

        return null;
    }

    /**
     * Возвращает название тарифа доставки.
     */
    public function getShippingName(): string
    {
        $variant = $this->getSelectedVariant();
        return self::scalarString($variant['name'] ?? '');
    }

    /**
     * Возвращает стоимость доставки (или null если не задана).
     */
    public function getShippingRate(): ?float
    {
        $variant = $this->getSelectedVariant();
        $rate = $variant['rate'] ?? null;
        return is_scalar($rate) ? (float) $rate : null;
    }

    /**
     * Возвращает тип доставки (pickup / courier / todoor / post).
     */
    public function getShippingType(): string
    {
        $variant = $this->getSelectedVariant();
        return self::scalarString($variant['type'] ?? '');
    }

    /**
     * Возвращает ориентировочный срок доставки.
     */
    public function getShippingEstDelivery(): string
    {
        $variant = $this->getSelectedVariant();
        return self::scalarString($variant['est_delivery'] ?? '');
    }

    /**
     * Возвращает описание варианта доставки.
     * Fallback: ищет description в custom_data.
     */
    public function getShippingDescription(): string
    {
        $variant = $this->getSelectedVariant();
        $description = self::scalarString($variant['description'] ?? '');

        if ($description === '') {
            foreach (self::arrayValue($variant['custom_data'] ?? []) as $type_data) {
                if (is_array($type_data) && !empty($type_data['description'])) {
                    $description = self::scalarString($type_data['description']);
                    if ($description !== '') {
                        break;
                    }
                }
            }
        }

        return $description;
    }

    /**
     * Возвращает имя плагина доставки (plugin_name).
     */
    public function getShippingPluginName(): string
    {
        $variant = $this->getSelectedVariant();
        return self::scalarString($variant['plugin_name'] ?? '');
    }

    /**
     * Возвращает название тарифа/сервиса доставки (service).
     */
    public function getShippingService(): string
    {
        $variant = $this->getSelectedVariant();
        return self::scalarString($variant['service'] ?? '');
    }

    /**
     * Возвращает инструкцию "как добраться" (way).
     */
    public function getShippingWay(): string
    {
        $service_data = $this->getFirstCustomData();
        return self::scalarString($service_data['way'] ?? '');
    }

    /**
     * Возвращает количество дней хранения заказа.
     */
    public function getShippingStorageDays(): string
    {
        $service_data = $this->getFirstCustomData();
        return self::scalarString($service_data['storage']['storage_days'] ?? '');
    }

    /**
     * Возвращает фотографии пункта выдачи.
     *
     * @return array<int, mixed>
     */
    public function getShippingPhotos(): array
    {
        $service_data = $this->getFirstCustomData();
        $photos = $service_data['photos'] ?? [];
        return is_array($photos) ? $photos : [];
    }

    /**
     * Возвращает HTML расписания пункта выдачи.
     *
     * Поддерживает два формата, которые генерирует ядро Shop-Script:
     * - pickup_schedule.days (массив) — современный формат (CDEK и др.); рендерится собственной
     *   разметкой плагина, см. renderPickupSchedule()
     * - pickup_schedule_html (строка) — устаревший формат от плагинов, возвращается как есть
     *
     * Источник — не getSelectedVariant(), в отличие от остальных полей доставки. Структурированное
     * расписание существует ровно в одном месте: shopCheckoutDetailsStep::process() промотирует
     * custom_data['pickup']['schedule'] в pickup_schedule/pickup_schedule_html и кладёт результат
     * только в свой vars ('shipping_rate' => $updated_selected_variant). Шаг shipping этого не делает
     * вовсе, поэтому ни data.shipping.selected_variant, ни vars.shipping.shipping_rate расписания
     * не содержат — чтение оттуда давало пустую строку всегда, при любом способе доставки
     * (docs/bugs/zen-delivery-schedule-source-missing.md). Читаем ровно тот массив, из которого
     * рисует расписание сам ядровый виджет (details.html), — вывод гарантированно совпадает.
     *
     * Готовый HTML стороннего плагина (pickup_schedule_html) в свою сетку не заворачиваем:
     * его внутренняя структура неизвестна, а grid из css/zenmode.css рассчитан ровно на пары
     * ячеек, которые отдаёт renderPickupSchedule(). Чужая разметка идёт как есть.
     */
    public function getShippingScheduleHtml(): string
    {
        // Второй кандидат — на случай плагина доставки, кладущего готовое расписание прямо в тариф.
        $candidates = [
            $this->params['vars']['details']['shipping_rate'] ?? [],
            $this->getSelectedVariant(),
        ];

        foreach ($candidates as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $schedule = $variant['pickup_schedule'] ?? [];
            if (is_array($schedule) && !empty($schedule['days']) && is_array($schedule['days'])) {
                return $this->renderPickupSchedule($schedule);
            }

            $html = $variant['pickup_schedule_html'] ?? '';
            if (is_string($html) && $html !== '') {
                return $html;
            }
        }

        return '';
    }

    /**
     * Возвращает адрес пункта выдачи из custom_data[type]['description'].
     * Отдельно от getShippingDescription() — всегда даёт адрес ПВЗ,
     * а не общее описание метода доставки.
     */
    public function getShippingPickupAddress(): string
    {
        $service_data = $this->getFirstCustomData();
        return self::scalarString($service_data['description'] ?? '');
    }

    /**
     * Рендерит структурированное расписание (pickup_schedule) на собственной разметке плагина.
     *
     * Состав данных повторяет details.html: пометка дня (`additional`, напр. «Обед 13:00–14:00»)
     * и подсказка с часовым поясом, когда пояс ПВЗ отличается от пояса покупателя. А вот классы
     * ядра (`.wa-day-wrapper`, `.wa-date`, `.wa-time`) не переиспользуются: правила ядра требуют
     * предков `.wa-details-rates-section .wa-schedule-wrapper .wa-days-wrapper`, которых в плоской
     * Zen-карточке нет, и день разъезжался в две строки вместо табличной строки
     * (docs/bugs/zen-photos-css-scope-broken.md). Две колонки задаёт css/zenmode.css гридом.
     *
     * День отдаёт ровно две ячейки-потомка — своей обёртки у дня нет, иначе колонки соседних
     * дней не выровняются по одной сетке.
     *
     * @param array $schedule Массив pickup_schedule (days, timezone, timezone_text, user_timezone)
     * @return string HTML: .prefill-zen-schedule с парами «дата — часы»
     */
    private function renderPickupSchedule(array $schedule): string
    {
        $locale = substr((string)wa()->getLocale(), 0, 2);
        $items = '';

        // Ядро показывает пояс ПВЗ только когда он расходится с поясом покупателя: совпадающий
        // пояс в подсказке — шум. user_timezone проставляет тот же шаг details, что и расписание.
        $timezone = (string)($schedule['timezone'] ?? '');
        $user_timezone = (string)($schedule['user_timezone'] ?? '');
        $timezone_title = '';
        if ($timezone !== '' && $timezone !== $user_timezone) {
            $timezone_text = (string)($schedule['timezone_text'] ?? '');
            if ($timezone_text !== '') {
                $timezone_title = ' title="' . htmlspecialchars($timezone_text, ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        foreach ($schedule['days'] as $day) {
            if (!is_array($day)) {
                continue;
            }

            $date = htmlspecialchars((string)($day['date_formatted'] ?? ''), ENT_QUOTES, 'UTF-8');
            $wday = (string)($day['weekday_full'] ?? '');
            if ($locale === 'ru') {
                $wday = mb_strtolower($wday, 'UTF-8');
            }
            $wday = htmlspecialchars($wday, ENT_QUOTES, 'UTF-8');

            if (!empty($day['works'])) {
                $start = htmlspecialchars((string)($day['time_start'] ?? ''), ENT_QUOTES, 'UTF-8');
                $end   = htmlspecialchars((string)($day['time_end'] ?? ''), ENT_QUOTES, 'UTF-8');
                $value = '<span class="prefill-zen-schedule-time"' . $timezone_title . '>' . $start . '—' . $end . '</span>';
            } else {
                // _w(), а не _wp(): намеренно чужой ключ из локали ядра shop, не своей.
                // Тот же ключ выводит ядро в этой же секции ("day off" → details.html:253),
                // и совпадение формулировки со свёрнутой карточкой — ровно цель этой строки.
                // Ключ принадлежит Shop-Script и может исчезнуть в будущей версии — тогда
                // покупатель увидит английское "day off" вместо перевода (issue-100 §5).
                $value = '<span class="prefill-zen-schedule-off">'
                    . htmlspecialchars(_w('day off'), ENT_QUOTES, 'UTF-8')
                    . '</span>';
            }

            // Ядро режет пометку по 64 символа (details.html), длиннее в вёрстку не влезает.
            $additional = mb_substr((string)($day['additional'] ?? ''), 0, 64);
            if ($additional !== '') {
                $value .= '<span class="prefill-zen-schedule-note">'
                    . htmlspecialchars($additional, ENT_QUOTES, 'UTF-8')
                    . '</span>';
            }

            $items .= '<span class="prefill-zen-schedule-date">' . $date . ($wday !== '' ? ', ' . $wday : '') . '</span>'
                . '<span class="prefill-zen-schedule-hours">' . $value . '</span>';
        }

        if ($items === '') {
            return '';
        }

        return '<span class="prefill-zen-schedule">' . $items . '</span>';
    }

    /**
     * Возвращает кастомные поля доставки (data.shipping.custom).
     *
     * @return array<string, mixed>
     */
    public function getShippingCustomFields(): array
    {
        return self::arrayValue($this->params['data']['shipping']['custom'] ?? []);
    }

    // -------------------------------------------------------------------------
    // Адрес
    // -------------------------------------------------------------------------

    /**
     * Возвращает город доставки.
     */
    public function getCity(): string
    {
        return $this->findAddressField('city');
    }

    /**
     * Возвращает регион доставки (код или название).
     */
    public function getRegion(): string
    {
        return self::scalarString(
            $this->params['data']['shipping']['address']['region']
                ?? $this->params['data']['input']['region']['region']
                ?? $this->params['vars']['region']['selected_values']['region_id']
                ?? ''
        );
    }

    /**
     * Возвращает почтовый индекс.
     */
    public function getZip(): string
    {
        return $this->findAddressField('zip');
    }

    /**
     * Возвращает улицу.
     */
    public function getStreet(): string
    {
        return $this->findAddressField('street');
    }

    /**
     * Возвращает номер дома/строения.
     */
    public function getBuilding(): string
    {
        return $this->findAddressField('building');
    }

    /**
     * Возвращает номер квартиры/офиса.
     */
    public function getApartment(): string
    {
        return $this->findAddressField('apartment');
    }

    /**
     * Возвращает кастомные поля адреса (всё, кроме стандартных).
     *
     * @return array<string, mixed>
     */
    public function getCustomAddressFields(): array
    {
        $standard = ['city', 'region', 'zip', 'street', 'building', 'apartment', 'country', 'lat', 'lng'];

        $shipping_address = self::arrayValue($this->params['data']['shipping']['address'] ?? []);
        $details_address = self::arrayValue($this->params['data']['input']['details']['shipping_address'] ?? []);

        $custom = [];

        foreach ($shipping_address as $k => $v) {
            if (!in_array($k, $standard, true)) {
                $custom[$k] = $v;
            }
        }

        foreach ($details_address as $k => $v) {
            if (!in_array($k, $standard, true) && !isset($custom[$k])) {
                $custom[$k] = $v;
            }
        }

        return $custom;
    }

    // -------------------------------------------------------------------------
    // Оплата
    // -------------------------------------------------------------------------

    /**
     * Возвращает ID выбранного метода оплаты.
     */
    public function getPaymentId(): string
    {
        return self::scalarString($this->params['data']['payment']['id'] ?? '');
    }

    /**
     * Возвращает название метода оплаты.
     * Ищет сначала в vars.payment.methods, затем через PluginsProvider.
     */
    public function getPaymentName(): string
    {
        $payment_id = $this->getPaymentId();
        if ($payment_id === '') {
            return '';
        }

        $payment_methods = self::arrayValue($this->params['vars']['payment']['methods'] ?? []);
        if (isset($payment_methods[$payment_id])) {
            return self::scalarString($payment_methods[$payment_id]['name'] ?? '');
        }

        $all_payments = shopPrefillPluginPluginsProvider::getPaymentMethods();
        return self::scalarString($all_payments[$payment_id]['name'] ?? '');
    }

    /**
     * Возвращает описание метода оплаты.
     */
    public function getPaymentDescription(): string
    {
        $payment_id = $this->getPaymentId();
        if ($payment_id === '') {
            return '';
        }

        $payment_methods = self::arrayValue($this->params['vars']['payment']['methods'] ?? []);
        if (isset($payment_methods[$payment_id])) {
            return self::scalarString($payment_methods[$payment_id]['description'] ?? '');
        }

        $all_payments = shopPrefillPluginPluginsProvider::getPaymentMethods();
        return self::scalarString($all_payments[$payment_id]['description'] ?? '');
    }

    /**
     * Возвращает URL логотипа/иконки выбранного метода доставки.
     * Данные берутся из selected_variant (data.shipping.selected_variant или vars.shipping.shipping_rate).
     * Приоритет: logo → img → icon[48] → icon[24] → icon[16].
     *
     * @return string|null URL логотипа или null если недоступен
     */
    public function getShippingLogoUrl(): ?string
    {
        $variant = $this->getSelectedVariant();
        if (empty($variant)) {
            return null;
        }

        // logo — кастомный из БД (shop_plugin.logo), img — дефолтный из конфига плагина (= icon[48])
        $logo = self::scalarString($variant['logo'] ?? null) ?: self::scalarString($variant['img'] ?? null);
        return $logo !== '' ? $logo : null;
    }

    /**
     * Возвращает URL логотипа выбранного метода оплаты.
     * Приоритет: logo → img из $params['vars']['payment']['methods'].
     *
     * @return string|null URL логотипа или null если недоступен
     */
    public function getPaymentLogoUrl(): ?string
    {
        $payment_id = $this->getPaymentId();
        if ($payment_id === '') {
            return null;
        }

        $methods = self::arrayValue($this->params['vars']['payment']['methods'] ?? []);
        if (!isset($methods[$payment_id])) {
            return null;
        }

        $method = self::arrayValue($methods[$payment_id]);
        $logo = self::scalarString($method['logo'] ?? $method['img'] ?? null);
        return $logo !== '' ? $logo : null;
    }

    /**
     * Возвращает кастомные поля оплаты.
     * Приоритет: data.input.payment.custom → data.payment.custom
     *
     * @return array<string, mixed>
     */
    public function getCustomPaymentFields(): array
    {
        return self::arrayValue(
            $this->params['data']['input']['payment']['custom']
                ?? $this->params['data']['payment']['custom']
                ?? []
        );
    }

    // -------------------------------------------------------------------------
    // Ошибки
    // -------------------------------------------------------------------------

    /**
     * Возвращает delayed_errors для указанного шага.
     *
     * @param string $step Имя шага: auth, details, shipping, payment, region
     * @return array<int|string, mixed>
     */
    public function getDelayedErrors(string $step): array
    {
        return self::arrayValue($this->params['data'][$step]['delayed_errors'] ?? []);
    }

    /**
     * Возвращает обычные (критические) ошибки. Исключает маркер fast_render (Shop-Script).
     *
     * @return array<int|string, mixed>
     */
    public function getRegularErrors(): array
    {
        $errors = self::arrayValue($this->params['errors'] ?? []);
        $real_errors = array_filter($errors, static fn($e) => !self::isFastRenderSentinel($e));

        return array_values($real_errors);
    }

    /**
     * true, если в этом ответе шаг shipping не считался вообще (Shop-Script fast_render),
     * а не потому, что вариант доставки реально недоступен. См. shopCheckoutShippingStep::process():
     * при fast_render шаг выходит до вычисления data.shipping.selected_variant, поэтому
     * getShippingType() пуст точно так же, как при настоящей недоступности варианта.
     */
    public function isFastRender(): bool
    {
        $errors = self::arrayValue($this->params['errors'] ?? []);
        foreach ($errors as $error) {
            if (self::isFastRenderSentinel($error)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Shop-Script при fast_render добавляет в errors элемент ['fast_render' => true] —
     * это не ошибка валидации, а служебный маркер ответа шага доставки.
     */
    private static function isFastRenderSentinel($item): bool
    {
        return is_array($item)
            && count($item) === 1
            && array_key_exists('fast_render', $item);
    }

    /**
     * Ядро не отработало шаг в этом запросе — протокольная пустота (R1, P9).
     *
     * Результат шага пуст ровно в одном случае: process() не вызывался — упал шаг выше
     * (короткое замыкание) либо сработал fast_render, — и шаг прошёл через
     * shopCheckoutStep::prepare(), положив в vars.<шаг> пустой массив. Отработавший шаг
     * всегда кладёт хотя бы один ключ: `disabled` — когда выключен настройками магазина,
     * свой набор — когда посчитан (даже если способов не нашлось).
     *
     * Отсутствие ключа vars.<шаг> — неопределённость, а не пустота: по B2a отвечаем «нет».
     *
     * @param string $step auth | region | shipping | details | payment | confirm
     */
    public function isStepSkipped(string $step): bool
    {
        return isset($this->params['vars'][$step]) && $this->params['vars'][$step] === [];
    }

    /**
     * Возвращает ID шага, на котором произошла ошибка.
     */
    public function getErrorStepId(): ?string
    {
        $val = self::scalarString($this->params['error_step_id'] ?? null);
        return $val !== '' ? $val : null;
    }

    /**
     * Проверяет, не установлен ли чекбокс service_agreement.
     * Значение 0 = пользователь НЕ согласился.
     */
    public function hasServiceAgreementError(): bool
    {
        $value = $this->params['vars']['auth']['service_agreement'] ?? null;
        if ($value === null) {
            return false;
        }

        $checkout_config = $this->params['vars']['config'] ?? null;

        // В некоторых хуках (например, checkout_render_auth) ядро не прокидывает $checkout_config
        if ($checkout_config === null && class_exists('shopCheckoutConfig')) {
            try {
                $checkout_config = new shopCheckoutConfig(true);
            } catch (Exception $e) {
                // Игнорируем ошибки (например, если нет нужных параметров роутинга)
            }
        }

        if ($checkout_config !== null) {
            // ВАЖНО: Мы должны убедиться, что администратор включил чекбокс.
            // Если настройка отключена или стоит режим 'notice' (просто текст),
            // то ошибки чекбокса быть не может.
            $agreement = $checkout_config['customer']['service_agreement'] ?? null;
            if ($agreement !== 'checkbox') {
                return false;
            }
        }

        // Ошибка только если опция включена, но пользователь явно не согласился (значение 0)
        return $value === 0 || $value === '0';
    }

    /**
     * Шаг ядра → группа дзен-режима. `confirm` намеренно отсутствует: см. getBlockingGroup().
     */
    private const STEP_TO_GROUP = [
        'auth'     => 'customer',
        'region'   => 'delivery',
        'shipping' => 'delivery',
        'details'  => 'delivery',
        'payment'  => 'payment',
    ];

    /**
     * Группа, из-за которой ядро сейчас короткозамкнуло конвейер шагов, либо null.
     *
     * Нужна для обратной связи покупателю: пока такая ошибка держится, сворачивать
     * блоки бессмысленно — сворачивание либо прячет само сообщение об ошибке, либо
     * прячет секцию, которую ядро в этом запросе вообще не отрисовало. Клиентская
     * валидация об этом не знает: серверные проверки (waEmailValidator, забаненный
     * адрес, чужой контакт, кончившийся товар) JS-регулярками не воспроизводятся.
     *
     * `confirm` исключён намеренно: его ошибки (не отмечено согласие с условиями)
     * штатно возникают на обычных пересчётах, ничего не короткозамыкают — шаг
     * последний — и блокировать из-за них сворачивание было бы навязчиво.
     *
     * @return string|null customer | delivery | payment
     */
    public function getBlockingGroup(): ?string
    {
        $step = $this->getErrorStepId();
        if ($step === null || !isset(self::STEP_TO_GROUP[$step])) {
            return null;
        }

        // Маркер fast_render отфильтрован в getRegularErrors() — это не ошибка валидации
        if (empty($this->getRegularErrors())) {
            return null;
        }

        return self::STEP_TO_GROUP[$step];
    }

    /**
     * Проверяет наличие ошибок в группе.
     *
     * @param string $group customer | delivery | payment
     */
    public function hasGroupErrors(string $group): bool
    {
        return $this->getGroupErrorsInfo($group)['has_errors'];
    }

    /**
     * Возвращает структурированную информацию об ошибках группы.
     * Совместима по формату с прежним extractGroupErrors() в ZenMode.
     *
     * @param string $group customer | delivery | payment
     * @return array{has_errors: bool, group: string, errors: array<string, mixed>}
     */
    public function getGroupErrorsInfo(string $group): array
    {
        $has_errors = false;
        $group_errors = [];

        switch ($group) {
            case 'customer':
                $auth_delayed = $this->getDelayedErrors('auth');
                if (!empty($auth_delayed)) {
                    $has_errors = true;
                    $group_errors['auth_delayed_errors'] = $auth_delayed;
                }

                if ($this->hasServiceAgreementError()) {
                    $has_errors = true;
                    $group_errors['service_agreement_error'] = true;
                }

                if ($this->getErrorStepId() === 'auth') {
                    $regular = $this->getRegularErrors();
                    if (!empty($regular)) {
                        $has_errors = true;
                        $group_errors['regular_errors'] = $regular;
                    }
                }
                break;

            case 'delivery':
                $details_delayed = $this->getDelayedErrors('details');
                if (!empty($details_delayed)) {
                    $has_errors = true;
                    $group_errors['details_delayed_errors'] = $details_delayed;
                }

                if (in_array($this->getErrorStepId(), ['region', 'shipping', 'details'], true)) {
                    $regular = $this->getRegularErrors();
                    if (!empty($regular)) {
                        $has_errors = true;
                        $group_errors['regular_errors'] = $regular;
                    }
                }
                break;

            case 'payment':
                if ($this->getErrorStepId() === 'payment') {
                    $regular = $this->getRegularErrors();
                    if (!empty($regular)) {
                        $has_errors = true;
                        $group_errors['regular_errors'] = $regular;
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
     * Возвращает полный массив ошибок для debug-панели.
     * Совместим по формату с прежним extractCheckoutErrors() в CheckoutHooks.
     *
     * @return array{has_errors: bool, regular_errors: array, auth_delayed_errors: array, details_delayed_errors: array, service_agreement_error: bool, error_step_id: string|null}
     */
    public function getAllErrorsInfo(): array
    {
        $auth_delayed = $this->getDelayedErrors('auth');
        $details_delayed = $this->getDelayedErrors('details');
        $regular_errors = $this->getRegularErrors();
        $service_agreement_error = $this->hasServiceAgreementError();

        $all_delayed = array_merge($auth_delayed, $details_delayed);
        $has_errors = !empty($all_delayed) || !empty($regular_errors) || $service_agreement_error;

        return [
            'has_errors' => $has_errors,
            'regular_errors' => $regular_errors,
            'auth_delayed_errors' => $auth_delayed,
            'details_delayed_errors' => $details_delayed,
            'service_agreement_error' => $service_agreement_error,
            'error_step_id' => $this->getErrorStepId(),
        ];
    }

    // -------------------------------------------------------------------------
    // Debug / снапшот
    // -------------------------------------------------------------------------

    /**
     * Возвращает $params['data'] для debug / снапшота.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return self::arrayValue($this->params['data'] ?? []);
    }

    // -------------------------------------------------------------------------
    // Единственный сеттер — мутация prefill
    // -------------------------------------------------------------------------

    /**
     * Применяет prefill-данные к params['data']['input'].
     * Пишет изменения обратно в исходный $params по ссылке.
     *
     * @param array<string, mixed> $filled_order Данные из preFillCheckoutParams()
     */
    public function applyPrefillInput(array $filled_order): void
    {
        // is_array, а не isset: под `input` приезжает POST покупателя, и скаляр там
        // уронил бы deepMergeArrays(array $base, …) TypeError'ом (issue-95).
        if (empty($filled_order) || !is_array($this->params['data']['input'] ?? null)) {
            return;
        }

        $before = $this->params['data']['input'];
        $this->params['data']['input'] = shopPrefillPluginHelper::deepMergeArrays(
            $before,
            $filled_order
        );

        $this->is_prefilled = ($this->params['data']['input'] !== $before);
    }

    /**
     * Возвращает true, если applyPrefillInput() был вызван и что-то изменил.
     */
    public function isPrefilled(): bool
    {
        return $this->is_prefilled;
    }

    // -------------------------------------------------------------------------
    // Приватные вспомогательные методы
    // -------------------------------------------------------------------------

    /**
     * Приводит значение из $params к строке.
     *
     * Типы в $params ядром не гарантированы: `vars.auth.fields[*].value` и весь `data.input.*`
     * приезжают из POST покупателя как есть (`formatContactFields()` кастует только значение
     * из контакта, но не присланное), поэтому под скалярным ключом может оказаться массив —
     * его отрисует соседний плагин или тема, положившая многозначное поле в пространство имён
     * `auth[data]` / `details[shipping_address]` / `region`. Без приведения объявленный `: string`
     * превращает это в TypeError, а его не ловит ни waEvent (только Exception), ни render-хуки:
     * покупатель получает 500 вместо формы оформления (issue-95).
     *
     * Нескалярное значение трактуем как «значения нет»: по B2a при неопределённости плагин
     * отступает к стоковому чекауту, а не додумывает.
     *
     * @param mixed $value
     */
    private static function scalarString($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Приводит значение из $params к массиву — зеркало scalarString() для `: array`-геттеров.
     * Обратная подстановка так же реальна: `payment[custom]=x` кладёт под массивный ключ строку.
     *
     * @param mixed $value
     * @return array<int|string, mixed>
     */
    private static function arrayValue($value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Ищет значение поля адреса по нескольким источникам в порядке приоритета:
     * data.shipping.address → data.input.details.shipping_address → data.input.region → vars.region.selected_values
     */
    private function findAddressField(string $field): string
    {
        return self::scalarString(
            $this->params['data']['shipping']['address'][$field]
                ?? $this->params['data']['input']['details']['shipping_address'][$field]
                ?? $this->params['data']['input']['region'][$field]
                ?? $this->params['vars']['region']['selected_values'][$field]
                ?? ''
        );
    }

    /**
     * Возвращает первый элемент custom_data выбранного варианта доставки.
     * Используется для полей way, storage_days, photos.
     *
     * @return array<string, mixed>
     */
    private function getFirstCustomData(): array
    {
        $variant = $this->getSelectedVariant();
        $custom_data = self::arrayValue($variant['custom_data'] ?? []);

        if (!empty($custom_data)) {
            $first = reset($custom_data);
            return is_array($first) ? $first : [];
        }

        return [];
    }
}
