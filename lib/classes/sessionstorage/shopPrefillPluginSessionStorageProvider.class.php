<?php

class shopPrefillPluginSessionStorageProvider
{
    public bool $prefilled = false;

    private const SNAPSHOT_KEY = 'shop/prefill_snapshot';

    private array $storefront_settings;
    private waSessionStorage $storage;
    private ?shopPrefillPluginSectionChecker $section_checker = null;

    /**
     * @throws waException
     */
    public function __construct(array $storefront_settings = [])
    {
        $this->storage = wa()->getStorage();
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
     * Получает параметры checkout из хранилища
     *
     * @return array|null Параметры checkout или null если хранилище пустое
     */
    public function getCheckoutParams(): ?array
    {
        return $this->getStorage()->get('shop/checkout');
    }

    public function setCheckoutParams($params): bool
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

    /**
     * Заполняет параметры checkout с проверкой через SectionChecker.
     * Использует snapshot как промежуточный источник — приоритетнее fill_params,
     * но уступает текущим данным в checkout.
     *
     * @param shopPrefillPluginFillParams $params Параметры для предзаполнения
     * @throws waException
     * @throws waDbException
     */
    public function preFillCheckoutParams(shopPrefillPluginFillParams $params): array
    {
        if ($this->prefilled) {
            shopPrefillPluginLog::debug('Skipped prefill because it was already prefilled in the current request');
            return [];
        }

        $checkout_params = $this->getCheckoutParams();
        $checkout_params = is_array($checkout_params) ? $checkout_params : [];

        $snapshot = $this->getSnapshot();

        $final_params = [];
        $checker = $this->getSectionChecker();

        // Каждая секция проверяется НЕЗАВИСИМО.
        // Если секция пуста в checkout — пробуем сначала snapshot, потом fill_params.
        if ($checker->canPrefillSection('auth', $checkout_params)) {
            $snapshot_section = $this->getSnapshotSection('auth', $snapshot, $checker);
            $this->prepareAuthSectionParams($params, $final_params, $snapshot_section);
        }

        if ($checker->canPrefillSection('region', $checkout_params)) {
            $snapshot_section = $this->getSnapshotSection('region', $snapshot, $checker);
            $this->prepareRegionSectionParams($params, $final_params, $snapshot_section);
        }

        if ($checker->canPrefillSection('shipping', $checkout_params)) {
            $snapshot_section = $this->getSnapshotSection('shipping', $snapshot, $checker);
            $this->prepareShippingSectionParams($params, $final_params, $snapshot_section);
        }

        if ($checker->canPrefillSection('details', $checkout_params)) {
            $snapshot_section = $this->getSnapshotSection('details', $snapshot, $checker);
            $this->prepareDetailsSectionParams($params, $final_params, $snapshot_section);
        }

        if ($checker->canPrefillSection('payment', $checkout_params)) {
            $snapshot_section = $this->getSnapshotSection('payment', $snapshot, $checker);
            $this->preparePaymentSectionParams($params, $final_params, $snapshot_section);
        }

        if ($checker->canPrefillSection('confirm', $checkout_params)) {
            $snapshot_section = $this->getSnapshotSection('confirm', $snapshot, $checker);
            $this->prepareConfirmSectionParams($params, $final_params, $snapshot_section);
        }

        if (!empty($final_params)) {
            $merged = shopPrefillPluginHelper::deepMergeArrays($checkout_params, $final_params);
            $this->setCheckoutParams($merged);
            $this->saveSnapshot($merged);
            shopPrefillPluginLog::info('Successfully prefilled checkout params', [
                'filled_sections' => array_keys($final_params['order'] ?? []),
                'final_params_size' => strlen(json_encode($final_params))
            ]);
        } else {
            // Checkout уже полностью заполнен — обновляем snapshot актуальным состоянием
            if (!empty($checkout_params)) {
                $this->saveSnapshot($checkout_params);
            }
            shopPrefillPluginLog::debug('Prefill was evaluated but no params were filled (empty final_params)');
        }

        $this->prefilled = true;
        return $final_params['order'] ?? [];
    }

    /**
     * Проверяет, авторизован ли текущий пользователь
     */
    private function isUserAuthenticated(): bool
    {
        try {
            return wa()->getUser()->isAuth();
        } catch (waException $e) {
            shopPrefillPluginLog::warning('Failed checking user authentication in shopPrefillPluginSessionStorageProvider::isUserAuthenticated', [
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Подготавливает параметры auth секции для предзаполнения.
     * Приоритет: snapshot > fill_params
     *
     * Предзаполняет только для неавторизованных пользователей.
     */
    private function prepareAuthSectionParams(
        ?shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        ?array $snapshot_section
    ): void {
        if ($fill_params === null) {
            return;
        }

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
        ?shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        ?array $snapshot_section
    ): void {
        if ($fill_params === null) {
            return;
        }

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
        ?shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        ?array $snapshot_section
    ): void {
        if ($fill_params === null) {
            return;
        }

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
        ?shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        ?array $snapshot_section
    ): void {
        if ($fill_params === null) {
            return;
        }

        if ($snapshot_section !== null) {
            shopPrefillPluginLog::debug('Details section restored from snapshot');
            $final_params['order']['details'] = $snapshot_section;
            return;
        }

        $street = $fill_params->getStreet();
        if ($street) {
            $final_params['order']['details']['shipping_address']['street'] = $street;
        }
    }

    /**
     * Подготавливает параметры payment секции.
     * Приоритет: snapshot > fill_params
     */
    private function preparePaymentSectionParams(
        ?shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        ?array $snapshot_section
    ): void {
        if ($fill_params === null) {
            return;
        }

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
        ?shopPrefillPluginFillParams $fill_params,
        array &$final_params,
        ?array $snapshot_section
    ): void {
        if ($fill_params === null) {
            return;
        }

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
        $checkout_params = $this->getCheckoutParams() ?: [];
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
        // Шаг 1: Очищаем хранилище checkout и snapshot
        $this->getStorage()->remove('shop/checkout');
        $this->getStorage()->remove(self::SNAPSHOT_KEY);

        // Шаг 2: Сбрасываем флаг prefilled (для текущего запроса)
        $this->prefilled = false;

        shopPrefillPluginLog::info('Initiating Reset & Refill procedure');

        // Шаг 3: Заново предзаполняем
        $this->preFillCheckoutParams($params);
    }

}
