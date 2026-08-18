<?php

class shopPrefillPluginSessionStorageProvider
{
    public bool $prefilled = false;

    private const SNAPSHOT_KEY = 'shop/prefill_snapshot';

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
     * Получает снимок предыдущего состояния checkout
     *
     * @return array|null Снимок или null если отсутствует
     */
    public function getSnapshot(): ?array
    {
        $snapshot = $this->getStorage()->get(self::SNAPSHOT_KEY);
        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * Сохраняет снимок текущего состояния checkout
     *
     * @param array $checkout_params Актуальные параметры checkout
     */
    public function saveSnapshot(array $checkout_params): void
    {
        try {
            $this->getStorage()->set(self::SNAPSHOT_KEY, $checkout_params);
            shopPrefillPluginLog::debug('Snapshot saved', [
                'sections' => array_keys($checkout_params['order'] ?? [])
            ]);
        } catch (waException $e) {
            shopPrefillPluginLog::warning('Failed saving prefill snapshot', [
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Очищает хранилище снапшота (Prefill Snapshot).
     * Используется для debug-панели «Сброс снапшота».
     */
    public function clearSnapshot(): void
    {
        $this->getStorage()->remove(self::SNAPSHOT_KEY);
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

    /**
     * Извлекает секцию из snapshot только если она содержит данные.
     * Использует isSectionFilled чтобы не подставлять пустые секции из snapshot.
     *
     * @param string $section_id ID секции
     * @param array|null $snapshot Снимок сессии
     * @param shopPrefillPluginSectionChecker $checker
     * @return array|null Данные секции или null если пустые/отсутствуют
     */
    private function getSnapshotSection(
        string $section_id,
        ?array $snapshot,
        shopPrefillPluginSectionChecker $checker
    ): ?array {
        if ($snapshot === null) {
            return null;
        }

        $section_data = $snapshot['order'][$section_id] ?? null;
        if ($section_data === null) {
            return null;
        }

        // Проверяем что в snapshot реально есть данные (не пустая секция)
        if (!$checker->isSectionFilled($section_id, $snapshot)) {
            return null;
        }

        return $section_data;
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
     * Порядок принципиален:
     *   1. посчитать доступные секции по сессии и настройкам;
     *   2. закрыть что можно из snapshot — ВСЕГДА, это бесплатно и не зависит от маркера;
     *   3. если пробелы остались — сверить маркер и только тогда идти в источник;
     *   4. записать checkout, snapshot и маркер.
     *
     * Маркер намеренно гейтит только шаг 3. Ранний выход из всего метода обесточил бы
     * восстановление из snapshot, которое обязано работать на каждом запросе (issue-53).
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
        $snapshot        = $this->getSnapshot();
        $checker         = $this->getSectionChecker();

        // Шаг 1: что вообще разрешено заполнять
        $available = [];
        foreach (self::SECTIONS as $section_id) {
            if ($checker->canPrefillSection($section_id, $checkout_params)) {
                $available[] = $section_id;
            }
        }

        // Источник не понадобится — в БД не идём и маркер не трогаем
        if (empty($available)) {
            $this->finishWithoutFill($checkout_params);
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

        $empty_params = new shopPrefillPluginFillParams();
        $final_params = [];
        $used_source  = false;

        foreach ($available as $section_id) {
            $snapshot_section = $this->getSnapshotSection($section_id, $snapshot, $checker);

            if ($snapshot_section !== null) {
                // Шаг 2: снапшот закрывает секцию сам, источник для неё не нужен
                $this->prepareSection($section_id, $empty_params, $final_params, $snapshot_section);
                continue;
            }

            if (!$source_allowed) {
                continue;
            }

            // Шаг 3: только здесь возможен поход в БД
            $this->prepareSection($section_id, $load(), $final_params, null);
            $used_source = true;
        }

        // Шаг 4. Маркер ставится и при пустом результате: если у источника нет данных
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
            $this->saveSnapshot($merged);
            shopPrefillPluginLog::info('Successfully prefilled checkout params', [
                'sections' => array_keys($final_params['order'] ?? []),
            ]);
            $this->prefilled = true;
            return $final_params['order'] ?? [];
        }

        $this->finishWithoutFill($checkout_params);
        return [];
    }

    /**
     * Ветка «нечего предзаполнять»: checkout уже полон — обновляем snapshot актуальным состоянием.
     */
    private function finishWithoutFill(array $checkout_params): void
    {
        if (!empty($checkout_params)) {
            $this->saveSnapshot($checkout_params);
        }
        shopPrefillPluginLog::debug('Prefill was evaluated but no params were filled (empty final_params)');
        $this->prefilled = true;
    }

    /**
     * Диспетчер секций: один вход вместо шести ветвлений в вызывающем коде.
     */
    private function prepareSection(
        string $section_id,
        shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        ?array $snapshot_section
    ): void {
        switch ($section_id) {
            case 'auth':
                $this->prepareAuthSectionParams($fill_params, $final_params, $snapshot_section);
                break;
            case 'region':
                $this->prepareRegionSectionParams($fill_params, $final_params, $snapshot_section);
                break;
            case 'shipping':
                $this->prepareShippingSectionParams($fill_params, $final_params, $snapshot_section);
                break;
            case 'details':
                $this->prepareDetailsSectionParams($fill_params, $final_params, $snapshot_section);
                break;
            case 'payment':
                $this->preparePaymentSectionParams($fill_params, $final_params, $snapshot_section);
                break;
            case 'confirm':
                $this->prepareConfirmSectionParams($fill_params, $final_params, $snapshot_section);
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
     * Проверяет, авторизован ли текущий пользователь
     */
    private function isUserAuthenticated(): bool
    {
        return $this->user_provider->isAuth();
    }

    /**
     * Подготавливает параметры auth секции для предзаполнения.
     * Приоритет: snapshot > fill_params
     *
     * Предзаполняет только для неавторизованных пользователей.
     */
    private function prepareAuthSectionParams(
        shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        ?array $snapshot_section
    ): void {
        // Для авторизованных пользователей auth данные берутся из контакта автоматически
        if ($this->isUserAuthenticated()) {
            return;
        }

        // Если есть snapshot — восстанавливаем из него
        if ($snapshot_section !== null) {
            shopPrefillPluginLog::debug('Auth section restored from snapshot');
            $final_params['order']['auth'] = $snapshot_section;
            return;
        }

        // Fallback: данные из прошлого заказа
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
     * Приоритет: snapshot > fill_params
     */
    private function prepareRegionSectionParams(
        shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        ?array $snapshot_section
    ): void {
        if ($snapshot_section !== null) {
            shopPrefillPluginLog::debug('Region section restored from snapshot');
            $final_params['order']['region'] = $snapshot_section;
            return;
        }

        $final_params['order']['region']['country'] = $fill_params->getCountry();
        $final_params['order']['region']['region'] = $fill_params->getRegion();
        $final_params['order']['region']['city'] = $fill_params->getCity();
        $final_params['order']['region']['zip'] = $fill_params->getZip();
    }

    /**
     * Подготавливает параметры shipping секции.
     * Приоритет: snapshot > fill_params
     */
    private function prepareShippingSectionParams(
        shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        ?array $snapshot_section
    ): void {
        if ($snapshot_section !== null) {
            shopPrefillPluginLog::debug('Shipping section restored from snapshot');
            $final_params['order']['shipping'] = $snapshot_section;
            return;
        }

        $final_params['order']['shipping']['type_id'] = $fill_params->getShippingTypeId();
        $final_params['order']['shipping']['variant_id'] = $fill_params->getShippingVariantId();

        if ($fill_params->getShippingCustom()) {
            foreach ($fill_params->getShippingCustom() as $param => $value) {
                $final_params['order']['details']['custom'][$param] = $value;
            }
        }
    }

    /**
     * Подготавливает параметры details секции (адрес доставки).
     * Приоритет: snapshot > fill_params
     */
    private function prepareDetailsSectionParams(
        shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        ?array $snapshot_section
    ): void {
        if ($snapshot_section !== null) {
            shopPrefillPluginLog::debug('Details section restored from snapshot');
            $final_params['order']['details'] = $snapshot_section;
            return;
        }

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
     * Приоритет: snapshot > fill_params
     */
    private function preparePaymentSectionParams(
        shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        ?array $snapshot_section
    ): void {
        if ($snapshot_section !== null) {
            shopPrefillPluginLog::debug('Payment section restored from snapshot');
            $final_params['order']['payment'] = $snapshot_section;
            return;
        }

        $final_params['order']['payment']['id'] = $fill_params->getPaymentId();
        if ($fill_params->getPaymentCustom()) {
            foreach ($fill_params->getPaymentCustom() as $param => $value) {
                $final_params['order']['payment']['custom'][$param] = $value;
            }
        }
    }

    /**
     * Подготавливает параметры confirm секции.
     * Приоритет: snapshot > fill_params
     */
    private function prepareConfirmSectionParams(
        shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        ?array $snapshot_section
    ): void {
        if ($snapshot_section !== null) {
            shopPrefillPluginLog::debug('Confirm section restored from snapshot');
            $final_params['order']['confirm'] = $snapshot_section;
            return;
        }

        $comment = $fill_params->getComment();
        if ($comment !== null) {
            $final_params['order']['confirm']['comment'] = $comment;
        }
    }

    /**
     * Применяет выбранный сценарий доставки к сессии.
     *
     * Замещает только секции region, details, shipping — не затрагивает auth, payment, confirm.
     * Передача null в качестве snapshot_section заставляет prepare-методы брать данные напрямую из $fill_params.
     *
     * @param shopPrefillPluginFillParams $fill_params Параметры выбранного сценария доставки
     */
    public function applyDeliveryAddress(shopPrefillPluginFillParams $fill_params): void
    {
        $checkout_params = $this->getCheckoutParams();
        $final_params = [];

        $this->prepareRegionSectionParams($fill_params, $final_params, null);
        $this->prepareDetailsSectionParams($fill_params, $final_params, null);
        $this->prepareShippingSectionParams($fill_params, $final_params, null);

        $merged = shopPrefillPluginHelper::deepMergeArrays($checkout_params, $final_params);

        if ($this->setCheckoutParams($merged)) {
            $this->saveSnapshot($merged);
            shopPrefillPluginLog::info('Delivery scenario applied via applyDeliveryAddress', [
                'shipping_type_id' => $fill_params->getShippingTypeId(),
                'shipping_variant_id' => $fill_params->getShippingVariantId(),
            ]);
        }
    }

    /**
     * Очищает форму, сбрасывает snapshot и заново предзаполняет.
     * Используется для debug кнопки "Reset & Refill"
     *
     * @param shopPrefillPluginFillParams $params Параметры для предзаполнения
     * @return void
     * @throws waException
     * @throws waDbException
     */
    public function resetAndRefill(shopPrefillPluginFillParams $params): void
    {
        // Шаг 1: Очищаем хранилище checkout, snapshot и маркер источника
        $this->getStorage()->remove('shop/checkout');
        $this->getStorage()->remove(self::SNAPSHOT_KEY);
        $this->clearSourceMarker();

        // Шаг 2: Сбрасываем флаг prefilled (для текущего запроса)
        $this->prefilled = false;

        shopPrefillPluginLog::info('Initiating Reset & Refill procedure');

        // Шаг 3: Заново предзаполняем
        $this->preFillCheckoutParams($params);
    }

}
