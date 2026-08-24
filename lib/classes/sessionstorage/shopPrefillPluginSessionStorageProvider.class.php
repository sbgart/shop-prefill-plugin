<?php

class shopPrefillPluginSessionStorageProvider
{
    public bool $prefilled = false;

    /**
     * Отпечаток источника, из которого уже предзаполняли в этой Webasyst-сессии.
     *
     * Нужен, чтобы не перечитывать один и тот же заказ на каждом checkout calculate.
     * Хранит только строку ключа: ни order_id, ни персональных данных, ни своего TTL —
     * маркер живёт ровно столько, сколько сессия.
     */
    private const SOURCE_KEY = 'shop/prefill_source';

    /**
     * Метка «этот заказ авторизует покупателя, а выбора у него не было».
     * Ставится при создании заказа и потребляется на следующей загрузке страницы:
     * по cookie этот случай неотличим от явного отказа от «Запомнить меня».
     */
    private const PENDING_AUTH_KEY = 'shop/prefill_pending_auth';

    /**
     * Последний подтверждённый способ оплаты (`id` + `custom`, без `html`).
     *
     * Ядро исключает `payment` из списка секций, отправляемых с реальными
     * значениями, при смене типа и варианта доставки (`Shipping.prototype.update`,
     * form.js:2003-2015) и при смене региона (`onRegionChange`, form.js:3600-3609) —
     * секция приходит `html === 'only'`, без полей, хотя покупатель её не трогал.
     * Кэш переживает эти два случая и самоинвалидируется в тот же момент, когда
     * секция говорит сама за себя и результат пуст (P9, узкое исключение из B2a).
     * См. docs/plans/payment-section-echo-cache.md.
     */
    private const PAYMENT_ECHO_KEY = 'shop/prefill_payment_echo';

    /**
     * Последний подтверждённый выбор доставки: `variant_id`, кастомные поля плагина
     * доставки (`details.custom`) и отпечаток адреса, под который выбор сделан.
     *
     * Нужен по той же причине, что и эхо оплаты, но по другому поводу: при ошибке
     * на шаге `auth` ядро короткозамыкает конвейер, прячет секцию `shipping` целиком
     * (shipping.html:41) и не рендерит её скрытые поля. Следующий POST сериализует
     * пустой DOM, а calculateAction() заменяет `order` целиком — выбор доставки
     * исчезает безвозвратно, вместе с датой и интервалом доставки.
     *
     * Отпечаток адреса — обязательная часть: смена региона шлёт секцию `clean`, то есть
     * ровно тот же `{html:'only'}`, что и короткое замыкание. Различить их можно только
     * по региону, ошибок в том запросе нет ни у той, ни у другой стороны.
     *
     * См. docs/bugs/shipping-payment-identity-lost-after-snapshot-removal.md
     */
    private const DELIVERY_ECHO_KEY = 'shop/prefill_delivery_echo';

    /**
     * Поля адреса, образующие отпечаток: по ним ядро считает список вариантов, тариф и срок.
     */
    private const REGION_FINGERPRINT_FIELDS = ['country', 'region', 'city', 'zip'];

    private array $storefront_settings;
    private waSessionStorage $storage;
    private shopPrefillPluginUserProvider $user_provider;
    private ?shopPrefillPluginSectionChecker $section_checker = null;

    /**
     * @throws waException
     */
    public function __construct(
        waSessionStorage $storage,
        shopPrefillPluginUserProvider $user_provider,
        array $storefront_settings = []
    )
    {
        $this->storage = $storage;
        $this->user_provider = $user_provider;
        $this->storefront_settings = $storefront_settings;
    }

    /**
     * Возвращает SectionChecker для проверки возможности предзаполнения
     */
    public function getSectionChecker(): shopPrefillPluginSectionChecker
    {
        return $this->section_checker ??= new shopPrefillPluginSectionChecker(
            $this->storefront_settings['prefill']['sections'] ?? []
        );
    }

    public function getStorage(): waSessionStorage
    {
        return $this->storage;
    }

    /**
     * Получает параметры checkout из хранилища.
     *
     * Всегда массив: ключа сессии может не быть (заказ вне обычного оформления),
     * а вызывающие всё равно нигде не отличают «нет сессии» от «пустая сессия».
     *
     * @return array Параметры checkout или пустой массив, если хранилище пустое
     */
    public function getCheckoutParams(): array
    {
        $params = $this->getStorage()->get('shop/checkout');

        return is_array($params) ? $params : [];
    }

    public function setCheckoutParams(array $params): bool
    {
        try {
            $this->getStorage()->set('shop/checkout', $params);

            return true;
        } catch (waException $e) {
            shopPrefillPluginLog::warning('Failed setting checkout params in shopPrefillPluginSessionStorageProvider::setCheckoutParams', [
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Помечает, что текущий заказ авторизует покупателя без его выбора.
     */
    public function setPendingAuth(): void
    {
        try {
            $this->getStorage()->set(self::PENDING_AUTH_KEY, true);
        } catch (waException $e) {
            shopPrefillPluginLog::warning('Failed setting pending auth flag', [
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Считывает метку и сразу гасит её — она одноразовая.
     */
    public function consumePendingAuth(): bool
    {
        $pending = (bool) $this->getStorage()->get(self::PENDING_AUTH_KEY);
        if ($pending) {
            $this->getStorage()->remove(self::PENDING_AUTH_KEY);
        }

        return $pending;
    }

    /** Порядок секций фиксирован: каждая проверяется независимо */
    private const SECTIONS = ['auth', 'region', 'shipping', 'details', 'payment', 'confirm'];

    /**
     * Предзаполнение по явно переданным данным.
     *
     * Путь явных действий покупателя (выбор варианта, force prefill, reset & refill):
     * источник уже известен, маркер не спрашиваем и не ставим.
     *
     * @throws waException
     * @throws waDbException
     */
    public function preFillCheckoutParams(shopPrefillPluginFillParams $params): array
    {
        return $this->applyPrefill(
            null,
            static function () use ($params) {
                return $params;
            },
            false
        );
    }

    /**
     * Предзаполнение из источника, который читается лениво и не чаще раза за сессию.
     *
     * @param string|null $source_key Отпечаток источника; null = источника заведомо нет (гость без куки)
     * @param callable    $loader     Вызывается только если после снапшота остались пустые секции
     * @throws waException
     * @throws waDbException
     */
    public function preFillCheckoutParamsFromSource(?string $source_key, callable $loader): array
    {
        return $this->applyPrefill($source_key, $loader, true);
    }

    /**
     * Заполняет параметры checkout с проверкой через SectionChecker.
     *
     * Секция, которую нельзя предзаполнять (`canPrefillSection()` вернул false),
     * не трогается вовсе — при неопределённости плагин отступает и отдаёт
     * покупателю стоковый чекаут, а не пытается угадать, что туда положить (B2a).
     *
     * @throws waException
     * @throws waDbException
     */
    private function applyPrefill(?string $source_key, callable $loader, bool $use_marker): array
    {
        if ($this->prefilled) {
            shopPrefillPluginLog::debug('Skipped prefill because it was already prefilled in the current request');
            return [];
        }

        $checkout_params = $this->getCheckoutParams();
        $checker         = $this->getSectionChecker();

        $available = [];
        foreach (self::SECTIONS as $section_id) {
            if ($checker->canPrefillSection($section_id, $checkout_params)) {
                $available[] = $section_id;
            }
        }

        // Источник не понадобится — в БД не идём и маркер не трогаем
        if (empty($available)) {
            shopPrefillPluginLog::debug('Prefill was evaluated but no params were filled (empty final_params)');
            $this->prefilled = true;
            return [];
        }

        // Ленивая загрузка: вызывается не более одного раза и только при реальной нужде
        $source        = null;
        $source_loaded = false;
        $load = static function () use (&$source, &$source_loaded, $loader) {
            if (!$source_loaded) {
                $source        = $loader();
                $source_loaded = true;
            }
            return $source;
        };

        // Маркер спрашиваем один раз, до цикла
        $source_allowed = true;
        if ($use_marker) {
            if ($source_key === null) {
                // Гость без куки: источника нет по определению. Маркер НЕ пишем —
                // запись в сессию подняла бы PHP-сессию и Set-Cookie: PHPSESSID
                // каждому анониму и боту (issue-53).
                $source_allowed = false;
            } elseif ($this->getAppliedSource() === $source_key) {
                shopPrefillPluginLog::debug('Source already applied in this session, skipping DB lookup');
                $source_allowed = false;
            }
        }

        $final_params = [];
        $used_source  = false;

        if ($source_allowed) {
            foreach ($available as $section_id) {
                $this->prepareSection($section_id, $load(), $final_params, $checker, $checkout_params);
                $used_source = true;
            }
        }

        // Маркер ставится и при пустом результате: если у источника нет данных
        // для этих секций, повторять тот же запрос на каждом calculate бессмысленно.
        if ($use_marker && $source_key !== null && $used_source) {
            $this->markSourceApplied($source_key);
        }

        // Секции могли собраться из одних null (гость без заказов) — такие листья не считаются данными
        // и не должны провоцировать запись в сессию. См. docs/codereview/issue-53-empty-prefill-writes-session.md
        $final_params = shopPrefillPluginHelper::stripEmptyLeaves($final_params);

        if (!empty($final_params)) {
            $merged = shopPrefillPluginHelper::deepMergeArrays($checkout_params, $final_params);
            $this->setCheckoutParams($merged);
            shopPrefillPluginLog::info('Successfully prefilled checkout params', [
                'sections' => array_keys($final_params['order'] ?? []),
            ]);
            $this->prefilled = true;
            return $final_params['order'] ?? [];
        }

        shopPrefillPluginLog::debug('Prefill was evaluated but no params were filled (empty final_params)');
        $this->prefilled = true;
        return [];
    }

    /**
     * Диспетчер секций: один вход вместо шести ветвлений в вызывающем коде.
     *
     * $checker/$checkout_params нужны только ветке 'shipping': её кастомные поля
     * доставки пишутся в чужое пространство имён details.custom (см. комментарий
     * prepareShippingSectionParams), и эта запись обязана спросить владение details
     * отдельно — canPrefillSection('shipping') его не проверяет (P1, issue-60).
     */
    private function prepareSection(
        string $section_id,
        shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        shopPrefillPluginSectionChecker $checker,
        array $checkout_params
    ): void {
        switch ($section_id) {
            case 'auth':
                $this->prepareAuthSectionParams($fill_params, $final_params);
                break;
            case 'region':
                $this->prepareRegionSectionParams($fill_params, $final_params);
                break;
            case 'shipping':
                $can_write_details = $checker->canPrefillSection('details', $checkout_params);
                $this->prepareShippingSectionParams($fill_params, $final_params, $can_write_details);
                break;
            case 'details':
                $this->prepareDetailsSectionParams($fill_params, $final_params);
                break;
            case 'payment':
                $this->preparePaymentSectionParams($fill_params, $final_params);
                break;
            case 'confirm':
                $this->prepareConfirmSectionParams($fill_params, $final_params);
                break;
        }
    }

    /**
     * Отпечаток источника, уже применённого в этой сессии.
     */
    public function getAppliedSource(): ?string
    {
        $value = $this->getStorage()->get(self::SOURCE_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function markSourceApplied(string $source_key): void
    {
        try {
            $this->getStorage()->set(self::SOURCE_KEY, $source_key);
        } catch (waException $e) {
            shopPrefillPluginLog::warning('Failed setting prefill source marker', [
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Сбрасывает маркер источника.
     *
     * Вызывается при оформлении заказа, reset/refill, force prefill, отзыве согласия
     * и очистке истории — то есть везде, где источник изменился или его надо перечитать.
     */
    public function clearSourceMarker(): void
    {
        $this->getStorage()->remove(self::SOURCE_KEY);
    }

    /**
     * Читает эхо-кэш секции payment.
     */
    private function getPaymentEcho(): ?array
    {
        $value = $this->getStorage()->get(self::PAYMENT_ECHO_KEY);

        return is_array($value) && !empty($value) ? $value : null;
    }

    private function savePaymentEcho(array $section): void
    {
        try {
            $this->getStorage()->set(self::PAYMENT_ECHO_KEY, $section);
        } catch (waException $e) {
            shopPrefillPluginLog::warning('Failed setting payment echo cache', [
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Сбрасывает эхо-кэш секции payment.
     *
     * Вызывается при оформлении заказа и явной очистке формы — там же, где
     * сбрасывается остальное состояние чекаута.
     */
    public function clearPaymentEcho(): void
    {
        $this->getStorage()->remove(self::PAYMENT_ECHO_KEY);
    }

    /**
     * Восстанавливает способ оплаты, механически обнулённый ядром при смене
     * типа/варианта доставки или региона, либо обновляет/чистит кэш по
     * текущему, реально отправленному покупателем выбору.
     *
     * «Секцию сегодня не спрашивали» от «покупатель сам оставил её пустой» отличает
     * состав присланных полей — см. isSectionMechanicallyClean() и план
     * docs/plans/payment-section-echo-cache.md. Совместимость восстановленного
     * `id` с текущей доставкой не проверяется: Этап 0 плана показал, что ядро
     * само рендерит несовместимое эхо нейтрально и блокирует его на
     * /order/create/, ровно как отсутствие выбора.
     *
     * Отпечатка адреса здесь нет намеренно, в отличие от syncDeliveryEcho(): способ
     * оплаты от региона не зависит, а несовместимость ловит само ядро.
     *
     * @return array|null Восстановленный кусок order.payment для текущего рендера
     */
    public function syncPaymentEcho(): ?array
    {
        if (!$this->getSectionChecker()->isGroupEnabledForSection('payment')) {
            return null;
        }

        $checkout_params = $this->getCheckoutParams();
        $checker         = $this->getSectionChecker();
        $id              = $checkout_params['order']['payment']['id'] ?? null;

        if ($checker->isSectionMechanicallyClean('payment', $checkout_params)) {
            if (!empty($id)) {
                // Недостижимо: «механически чисто» значит, что кроме html секция ничего
                // не прислала, а значит и id взяться неоткуда. Оставлено утверждением.
                return null;
            }

            $echo = $this->getPaymentEcho();
            if ($echo === null) {
                return null;
            }

            $merged = shopPrefillPluginHelper::deepMergeArrays($checkout_params, [
                'order' => ['payment' => $echo],
            ]);
            $this->setCheckoutParams($merged);

            shopPrefillPluginLog::debug('Payment section restored from echo cache', [
                'id' => $echo['id'] ?? null,
            ]);

            return $echo;
        }

        // Секция говорила сама за себя в этом запросе — это её настоящий выбор
        if (!empty($id)) {
            $section = ['id' => $id];
            if (!empty($checkout_params['order']['payment']['custom'])) {
                $section['custom'] = $checkout_params['order']['payment']['custom'];
            }
            $this->savePaymentEcho($section);
            shopPrefillPluginLog::debug('Payment echo cache updated with confirmed choice', ['id' => $id]);
        } elseif ($this->getPaymentEcho() !== null) {
            $this->clearPaymentEcho();
            shopPrefillPluginLog::debug('Payment echo cache cleared: customer left payment section empty');
        }

        return null;
    }

    /**
     * Читает эхо-кэш группы доставки.
     *
     * @return array{variant_id: string, custom: array, region: array}|null
     */
    private function getDeliveryEcho(): ?array
    {
        $value = $this->getStorage()->get(self::DELIVERY_ECHO_KEY);

        return is_array($value) && !empty($value['variant_id']) ? $value : null;
    }

    private function saveDeliveryEcho(array $echo): void
    {
        try {
            $this->getStorage()->set(self::DELIVERY_ECHO_KEY, $echo);
        } catch (waException $e) {
            shopPrefillPluginLog::warning('Failed setting delivery echo cache', [
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Сбрасывает эхо-кэш группы доставки.
     *
     * Вызывается при оформлении заказа и явной очистке формы — там же, где
     * сбрасывается остальное состояние чекаута.
     */
    public function clearDeliveryEcho(): void
    {
        $this->getStorage()->remove(self::DELIVERY_ECHO_KEY);
    }

    /**
     * Отпечаток адреса, под который сделан выбор доставки.
     *
     * Секция `region` никогда не приходит `clean` (сокращённый список секций есть только
     * в Shipping.prototype.update и onRegionChange, и region входит в оба), поэтому
     * сравнивать всегда есть с чем.
     *
     * @param array $checkout_params Параметры checkout
     * @return array<string, string>
     */
    private function getRegionFingerprint(array $checkout_params): array
    {
        $region      = $checkout_params['order']['region'] ?? [];
        $fingerprint = [];

        foreach (self::REGION_FINGERPRINT_FIELDS as $field) {
            $value              = is_array($region) ? ($region[$field] ?? null) : null;
            $fingerprint[$field] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $fingerprint;
    }

    /**
     * Восстанавливает выбор доставки, механически стёртый коротким замыканием
     * конвейера шагов, либо обновляет/чистит кэш по реально отправленному выбору.
     *
     * Условие восстановления двойное и оба звена обязательны:
     *   1. секция промолчала (`isSectionMechanicallyClean`) — её не было на странице;
     *   2. адрес не менялся — иначе прежний вариант относится к другому городу.
     *
     * Второе звено и отличает этот механизм от снятого снапшота: тот восстанавливал по
     * признаку пустоты и не умел отличить «ядро замкнуло конвейер» от «покупатель сменил
     * город». Смена региона шлёт секцию `clean`, то есть тот же `{html:'only'}`, и ошибок
     * в этом запросе нет — по `error_step_id` эти случаи неразличимы в принципе.
     *
     * Совместимость восстановленного варианта с корзиной не проверяется: ShippingStep
     * на каждом расчёте сопоставляет `variant_id` с заново посчитанным списком
     * (shopCheckoutShippingStep:251) и сам сбрасывает вариант, которого там нет.
     *
     * @return array Восстановленные куски order.* для текущего рендера (пусто, если нечего)
     */
    public function syncDeliveryEcho(): array
    {
        if (!$this->getSectionChecker()->isGroupEnabledForSection('shipping')) {
            return [];
        }

        $checkout_params = $this->getCheckoutParams();
        $checker         = $this->getSectionChecker();
        $variant_id      = $checkout_params['order']['shipping']['variant_id'] ?? null;

        if ($checker->isSectionMechanicallyClean('shipping', $checkout_params)) {
            $echo = $this->getDeliveryEcho();
            if ($echo === null) {
                return [];
            }

            // Вариант доставки осмыслен только для адреса, под который выбран: от региона
            // зависят и список вариантов, и тариф, и срок. Сменился адрес — выбор
            // недействителен, и группа обязана развернуться (Z2, B2a).
            if (($echo['region'] ?? null) !== $this->getRegionFingerprint($checkout_params)) {
                $this->clearDeliveryEcho();
                shopPrefillPluginLog::debug('Delivery echo dropped: region changed');
                return [];
            }

            $restored = ['shipping' => ['variant_id' => $echo['variant_id']]];

            // Кастомные поля пишем, только если и details промолчала: иначе перезапишем
            // то, что покупатель прислал в этом же запросе (P1).
            if (!empty($echo['custom']) && $checker->isSectionMechanicallyClean('details', $checkout_params)) {
                $restored['details'] = ['custom' => $echo['custom']];
            }

            $this->setCheckoutParams(
                shopPrefillPluginHelper::deepMergeArrays($checkout_params, ['order' => $restored])
            );

            shopPrefillPluginLog::debug('Delivery section restored from echo cache', [
                'variant_id' => $echo['variant_id'],
            ]);

            return $restored;
        }

        // Секция говорила сама за себя — это её настоящее состояние
        if (!empty($variant_id)) {
            $this->saveDeliveryEcho([
                'variant_id' => $variant_id,
                'custom'     => $checkout_params['order']['details']['custom'] ?? [],
                'region'     => $this->getRegionFingerprint($checkout_params),
            ]);
        } elseif ($this->getDeliveryEcho() !== null) {
            // Покупатель сменил тип и ещё не выбрал вариант: прежний выбор больше не его
            $this->clearDeliveryEcho();
            shopPrefillPluginLog::debug('Delivery echo cleared: customer left shipping section empty');
        }

        return [];
    }

    /**
     * Проверяет, авторизован ли текущий пользователь
     */
    private function isUserAuthenticated(): bool
    {
        return $this->user_provider->isAuth();
    }

    /**
     * Подготавливает параметры auth секции для предзаполнения.
     *
     * Предзаполняет только для неавторизованных пользователей.
     */
    private function prepareAuthSectionParams(
        shopPrefillPluginFillParams $fill_params,
        array &$final_params
    ): void {
        // Для авторизованных пользователей auth данные берутся из контакта автоматически
        if ($this->isUserAuthenticated()) {
            return;
        }

        $customer_type = $fill_params->getCustomerType();
        if ($customer_type) {
            $final_params['order']['auth']['mode'] = $customer_type;
        }

        $auth_data = $fill_params->getAuthData();
        foreach ($auth_data as $field_id => $value) {
            $final_params['order']['auth']['data'][$field_id] = $value;
        }
    }

    /**
     * Подготавливает параметры region секции.
     */
    private function prepareRegionSectionParams(
        shopPrefillPluginFillParams $fill_params,
        array &$final_params
    ): void {
        $final_params['order']['region']['country'] = $fill_params->getCountry();
        $final_params['order']['region']['region'] = $fill_params->getRegion();
        $final_params['order']['region']['city'] = $fill_params->getCity();
        $final_params['order']['region']['zip'] = $fill_params->getZip();
    }

    /**
     * Подготавливает параметры shipping секции.
     *
     * Вариант — единственная идентичность выбора доставки; тип ядро выводит из него
     * само (shopCheckoutShippingStep:226-234, :253) и вернёт в скрытое поле следующим
     * рендером. `type_id` мы не пишем вовсе — половину пары писать нельзя: тип без
     * варианта в сессии склеивается с чужим вариантом от прежнего выбора (issue-84 §1).
     *
     * `variant_id` пишется безусловно, `null` включительно — двум вызывающим нужно
     * противоположное, и `null` даёт обоим: applyPrefill() пропускает его через
     * stripEmptyLeaves() («нет варианта в источнике» = «не пишем ничего»),
     * applyDeliveryAddress() сливает без строгого выброса пустот и явно затирает
     * устаревший вариант на `null` — для явного выбора покупателя это правильно.
     *
     * Кастомные поля доставки (`getShippingCustom()`) смыслово принадлежат варианту
     * (issue-60), но физически лежат в чужом пространстве имён — `order.details.custom`,
     * не `order.shipping`, — поэтому запись сюда обязана спросить владение `details`
     * отдельно от владения `shipping`. `$can_write_details` — этот второй гейт:
     *   - из applyPrefill() приходит canPrefillSection('details', ...) — тихий prefill
     *     не имеет права трогать секцию, которую покупатель уже видел (P1);
     *   - из applyDeliveryAddress() приходит дефолт `true` — там это явный выбор
     *     покупателя, замещающий region/details/shipping целиком, а не тихая подстановка,
     *     и своего чекера у вызова нет.
     */
    private function prepareShippingSectionParams(
        shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        bool $can_write_details = true
    ): void {
        $final_params['order']['shipping']['variant_id'] = $fill_params->getShippingVariantId();

        if ($can_write_details && $fill_params->getShippingVariantId() !== null && $fill_params->getShippingCustom()) {
            foreach ($fill_params->getShippingCustom() as $param => $value) {
                $final_params['order']['details']['custom'][$param] = $value;
            }
        }
    }

    /**
     * Подготавливает параметры details секции (адрес доставки).
     */
    private function prepareDetailsSectionParams(
        shopPrefillPluginFillParams $fill_params,
        array &$final_params
    ): void {
        $street = $fill_params->getStreet();
        $final_params['order']['details']['shipping_address']['street'] = $street;

        // zip может быть в details вместо region (зависит от настройки администратора).
        // Пишем в обе секции — region.zip уже устанавливается в prepareRegionSectionParams,
        // здесь дублируем в details.shipping_address.zip чтобы предзаполниться в любом случае.
        $zip = $fill_params->getZip();
        if ($zip) {
            $final_params['order']['details']['shipping_address']['zip'] = $zip;
        }

        // Кастомные поля адреса доставки (building, apartment, podezd, floor и т.д.)
        foreach ($fill_params->getShippingAddressCustom() as $field => $value) {
            $final_params['order']['details']['shipping_address'][$field] = $value;
        }
    }

    /**
     * Подготавливает параметры payment секции.
     */
    private function preparePaymentSectionParams(
        shopPrefillPluginFillParams $fill_params,
        array &$final_params
    ): void {
        $final_params['order']['payment']['id'] = $fill_params->getPaymentId();
        if ($fill_params->getPaymentCustom()) {
            foreach ($fill_params->getPaymentCustom() as $param => $value) {
                $final_params['order']['payment']['custom'][$param] = $value;
            }
        }
    }

    /**
     * Подготавливает параметры confirm секции.
     */
    private function prepareConfirmSectionParams(
        shopPrefillPluginFillParams $fill_params,
        array &$final_params
    ): void {
        $comment = $fill_params->getComment();
        if ($comment !== null) {
            $final_params['order']['confirm']['comment'] = $comment;
        }
    }

    /**
     * Применяет выбранный сценарий доставки к сессии.
     *
     * Замещает только секции region, details, shipping — не затрагивает auth, payment, confirm.
     *
     * @param shopPrefillPluginFillParams $fill_params Параметры выбранного сценария доставки
     */
    public function applyDeliveryAddress(shopPrefillPluginFillParams $fill_params): void
    {
        $checkout_params = $this->getCheckoutParams();
        $final_params = [];

        $this->prepareRegionSectionParams($fill_params, $final_params);
        $this->prepareDetailsSectionParams($fill_params, $final_params);
        $this->prepareShippingSectionParams($fill_params, $final_params);

        $merged = shopPrefillPluginHelper::deepMergeArrays($checkout_params, $final_params);

        // Явный выбор варианта замещает секцию доставки целиком, а не дописывается в неё:
        // ядро смотрит на type_id только при пустом variant_id (shopCheckoutShippingStep:
        // 226-234), и чужой тип из прошлой вкладки покупателя не должен пережить этот выбор.
        // html не трогаем — это маркер владения (S2), а не данные.
        unset($merged['order']['shipping']['type_id']);

        if ($this->setCheckoutParams($merged)) {
            shopPrefillPluginLog::info('Delivery scenario applied via applyDeliveryAddress', [
                'shipping_variant_id' => $fill_params->getShippingVariantId(),
            ]);
        }
    }

    /**
     * Очищает форму и заново предзаполняет.
     * Используется для debug кнопки "Reset & Refill"
     *
     * @param shopPrefillPluginFillParams $params Параметры для предзаполнения
     * @return void
     * @throws waException
     * @throws waDbException
     */
    public function resetAndRefill(shopPrefillPluginFillParams $params): void
    {
        // Шаг 1: Очищаем хранилище checkout, маркер источника и эхо-кэши —
        // иначе «сброшенная» форма тут же получит обратно прежние доставку и оплату
        $this->getStorage()->remove('shop/checkout');
        $this->clearSourceMarker();
        $this->clearPaymentEcho();
        $this->clearDeliveryEcho();

        // Шаг 2: Сбрасываем флаг prefilled (для текущего запроса)
        $this->prefilled = false;

        shopPrefillPluginLog::info('Initiating Reset & Refill procedure');

        // Шаг 3: Заново предзаполняем
        $this->preFillCheckoutParams($params);
    }

}
