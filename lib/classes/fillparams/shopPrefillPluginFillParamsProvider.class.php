<?php

/**
 * Провайдер параметров предзаполнения чекаута
 *
 * Отвечает за получение параметров предзаполнения из БД:
 * - Для авторизованных: по last_order_id из shop_customer
 * - Для гостей: по производному имени параметра из токена в куке (см. GuestTokenStorage)
 */
class shopPrefillPluginFillParamsProvider
{
    private shopPrefillPluginOrderProvider    $order_provider;
    private shopPrefillPluginUserProvider     $user_provider;
    private shopPrefillPluginContactProvider  $contact_provider;
    private shopPrefillPluginGuestTokenStorage $guest_token_storage;
    private shopPrefillPluginLocationProvider $location_provider;

    /** Размер первой страницы истории заказов при сборе вариантов доставки */
    private const HISTORY_PAGE_SIZE = 50;

    /** Потолок размера страницы: дальше растить нечего — память дороже лишнего запроса */
    private const HISTORY_PAGE_MAX = 400;

    /** @var shopPrefillPluginFillParamsCollection|null Коллекция параметров предзаполнения */
    private ?shopPrefillPluginFillParamsCollection $fill_params_collection = null;

    /** @var int|null Лимит, с которым собрана закэшированная коллекция */
    private ?int $fill_params_collection_limit = null;

    /**
     * Кэш результата getFillParams() на время запроса, по ключу источника.
     *
     * Статический намеренно: waEvent пересоздаёт объект плагина на каждый хук (issue-73),
     * а на /order/ срабатывают и frontend_head, и checkout_before_auth — поле экземпляра
     * не пережило бы одну загрузку страницы.
     *
     * @var array<string, shopPrefillPluginFillParams>
     */
    private static array $fill_params_memo = [];

    public function __construct(
        shopPrefillPluginOrderProvider $order_provider,
        shopPrefillPluginUserProvider $user_provider,
        shopPrefillPluginContactProvider $contact_provider,
        shopPrefillPluginGuestTokenStorage $guest_token_storage,
        shopPrefillPluginLocationProvider $location_provider
    ) {
        $this->order_provider     = $order_provider;
        $this->user_provider      = $user_provider;
        $this->contact_provider   = $contact_provider;
        $this->guest_token_storage = $guest_token_storage;
        $this->location_provider  = $location_provider;
    }

    /**
     * Получает параметры предзаполнения из последнего заказа
     *
     * Логика:
     * - Авторизованные: из БД по contact_id (последний заказ)
     * - Неавторизованные: из БД по хешу гостя из куки (последний заказ с этим хешем)
     *
     * @param int|null $fill_params_id ID конкретного заказа (для выбора из списка адресов)
     * @return shopPrefillPluginFillParams Параметры предзаполнения
     */
    public function getFillParams(?int $fill_params_id = null): shopPrefillPluginFillParams
    {
        $memo_key = ($this->getSourceKey() ?? 'none') . '|' . ($fill_params_id ?? '');
        if (isset(self::$fill_params_memo[$memo_key])) {
            return self::$fill_params_memo[$memo_key];
        }

        // Авторизованные пользователи: данные из БД по contact_id
        if ($this->user_provider->isAuth()) {
            $fill_params = $this->getFillParamsForAuthorized($fill_params_id);
        } else {
            // Неавторизованные: данные из БД по токену гостя
            $fill_params = $this->getFillParamsForGuest();
        }

        return self::$fill_params_memo[$memo_key] = $fill_params;
    }

    /**
     * Отпечаток источника предзаполнения — без единого запроса к БД.
     *
     * Используется маркером в сессии, чтобы не перечитывать один и тот же источник
     * на каждом checkout calculate. Вход, выход и смена гостевой куки меняют ключ сами.
     *
     * @return string|null null означает «источника заведомо нет» (гость без куки)
     */
    public function getSourceKey(): ?string
    {
        if ($this->user_provider->isAuth()) {
            return 'user:' . $this->user_provider->getId();
        }

        $token = $this->guest_token_storage->getToken();
        if ($token === null) {
            return null;
        }

        return 'guest:' . $this->guest_token_storage->getLookupId($token);
    }

    /**
     * Сбрасывает кэш результата — для явных действий, которые обязаны перечитать источник.
     */
    public static function clearMemo(): void
    {
        self::$fill_params_memo = [];
    }

    /**
     * Получает параметры предзаполнения для авторизованного пользователя
     *
     * @param int|null $order_id Конкретный ID заказа (или null для последнего)
     * @return shopPrefillPluginFillParams
     */
    private function getFillParamsForAuthorized(?int $order_id = null): shopPrefillPluginFillParams
    {
        $contact_id = $this->user_provider->getId();
        shopPrefillPluginLog::debug('Loading fill params for authorized user', ['contact_id' => $contact_id, 'requested_order_id' => $order_id]);

        // Передан конкретный заказ (выбор адреса из списка) — используем только если заказ принадлежит пользователю
        if ($order_id) {
            $order_contact_id = $this->order_provider->getContactIdFromOrder($order_id);
            if ($order_contact_id && (int) $order_contact_id === (int) $contact_id) {
                $fill_params = $this->getFillParamsByOrderId($order_id);
                if ($fill_params !== null) {
                    shopPrefillPluginLog::debug('Fill params loaded from specific order', ['order_id' => $order_id]);
                    return $fill_params;
                }
            }
        }

        // Иначе — последний заказ пользователя
        $last_order_id = $this->order_provider->getLastOrderIdByContactId($contact_id);
        if (! $last_order_id) {
            shopPrefillPluginLog::debug('No previous orders found for authorized user', ['contact_id' => $contact_id]);
            return new shopPrefillPluginFillParams();
        }

        shopPrefillPluginLog::debug('Fill params loaded from last order', ['order_id' => $last_order_id]);
        return $this->getFillParamsByOrderId($last_order_id) ?? new shopPrefillPluginFillParams();
    }

    /**
     * Параметры предзаполнения по ID заказа или null, если заказ без параметров
     *
     * @param int $order_id
     * @return shopPrefillPluginFillParams|null
     */
    private function getFillParamsByOrderId(int $order_id): ?shopPrefillPluginFillParams
    {
        $order_params = $this->order_provider->getOrderParams($order_id);
        if (! $order_params) {
            return null;
        }
        return $this->getFillParamsByOrderParams($order_params, $order_id);
    }

    /**
     * Получает параметры предзаполнения для гостя (неавторизованного)
     *
     * @return shopPrefillPluginFillParams
     */
    private function getFillParamsForGuest(): shopPrefillPluginFillParams
    {
        // Нет валидной куки — гость ничего не заказывал: выходим без единого запроса.
        // Куку выдаёт только оформление заказа, поэтому её отсутствие однозначно.
        $param_name = $this->guest_token_storage->getParamName();
        if ($param_name === null) {
            shopPrefillPluginLog::debug('No guest token: skipping source lookup');
            return new shopPrefillPluginFillParams();
        }

        // Точное равенство по индексируемой колонке name
        $order_id = $this->order_provider->getLastOrderIdByGuestParam($param_name);
        if (! $order_id) {
            shopPrefillPluginLog::debug('No previous orders found for guest');
            return new shopPrefillPluginFillParams();
        }

        shopPrefillPluginLog::debug('Fill params loaded from guest order', ['order_id' => $order_id]);
        return $this->getFillParamsByOrderId($order_id) ?? new shopPrefillPluginFillParams();
    }

    /**
     * Получает коллекцию вариантов доставки авторизованного пользователя
     *
     * Формирует коллекцию на основе истории заказов пользователя,
     * удаляя дубликаты по параметрам доставки.
     *
     * Гостевая cookie используется только для автопредзаполнения последнего заказа.
     * Она намеренно не даёт доступ к истории адресов на общем браузере.
     *
     * @param int $limit Сколько различных вариантов набрать (настройка my_delivery_variants_limit)
     * @return shopPrefillPluginFillParamsCollection Коллекция параметров предзаполнения
     */
    public function getFillParamsCollection(
        int $limit = shopPrefillPluginFillParamsCollection::DEFAULT_LIMIT
    ): shopPrefillPluginFillParamsCollection {
        if ($this->fill_params_collection && $this->fill_params_collection_limit === $limit) {
            return $this->fill_params_collection;
        }

        $this->fill_params_collection       = new shopPrefillPluginFillParamsCollection();
        $this->fill_params_collection_limit = $limit;

        // Defense in depth: даже внутренний вызов не должен строить гостевую историю.
        if (!$this->user_provider->isAuth()) {
            return $this->fill_params_collection;
        }

        $unique_orders_ids = $this->collectUniqueDeliveryOrderIds($this->user_provider->getId(), $limit);
        if (empty($unique_orders_ids)) {
            return $this->fill_params_collection;
        }

        // ASC (старые → новые) для консистентности коллекции: порядок показа задаёт экшен
        sort($unique_orders_ids);

        // Полные параметры и строки заказов — только для отобранных вариантов, по одному запросу на всех
        $orders_params = $this->order_provider->getOrdersParamsByIds($unique_orders_ids);
        $this->order_provider->preloadOrderRows($unique_orders_ids);

        foreach ($unique_orders_ids as $order_id) {
            if (empty($orders_params[$order_id])) {
                continue;
            }

            $this->fill_params_collection->add(
                $this->getFillParamsByOrderParams($orders_params[$order_id], $order_id)
            );
        }

        return $this->fill_params_collection;
    }

    /**
     * ID заказов с различающимися вариантами доставки, от новых к старым.
     *
     * Лимит меряется в вариантах, а не в заказах: покупатель, полсотни раз подряд
     * заказавший одним способом, иначе теряет свой редкий второй адрес — он просто
     * не попадает в окно выборки (issue-68). Поэтому история добирается страницами,
     * пока не наберётся $limit различных вариантов или заказы не кончатся.
     *
     * Страница растёт вдвое (50 → 100 → 200 → …): у покупателя с двумя-тремя адресами
     * всё находится на первой, а редкий вариант из глубины истории стоит единиц запросов,
     * а не сотни страниц по 50.
     *
     * @return int[] ID заказов, каждый со своим вариантом доставки
     */
    private function collectUniqueDeliveryOrderIds(int $contact_id, int $limit): array
    {
        $active_shipping_instances = shopPrefillPluginPluginsProvider::getShippingMethods();

        $seen_delivery_options = [];
        $unique_orders_ids     = [];

        $page_size = self::HISTORY_PAGE_SIZE;
        $offset    = 0;

        while (count($unique_orders_ids) < $limit) {
            $orders_ids = $this->order_provider->getUserOrdersId($contact_id, $page_size, $offset);
            if (empty($orders_ids)) {
                break;
            }

            // Только подпись варианта: полные параметры дочитываются потом, для выживших
            $orders_params = $this->order_provider->getOrdersDeliveryParamsByIds($orders_ids);

            // $orders_ids уже DESC — первый встреченный сценарий считается актуальным
            foreach ($orders_ids as $order_id) {
                if (count($unique_orders_ids) >= $limit) {
                    break;
                }

                $order_params = $orders_params[$order_id] ?? [];

                // Единственный ключевой параметр доставки — вариант (shipping_id + rate_id).
                // Тип из него выводит ядро (shopCheckoutShippingStep:253), спрашивать
                // про тип здесь нечего.
                if (shopPrefillPluginFillParamsHelper::deliveryVariantId($order_params) === null) {
                    continue;
                }

                // Пропускаем, если инстанс доставки был отключен или удален администратором
                $shipping_instance_id = (int) $order_params['shipping_id'];
                if (! isset($active_shipping_instances[$shipping_instance_id])) {
                    continue;
                }

                // Без order_id: только адрес + параметры доставки из order_params, без запросов к БД
                $candidate = $this->getFillParamsByOrderParams($order_params);

                foreach ($seen_delivery_options as $seen) {
                    if ($candidate->isSameDeliveryOption($seen)) {
                        continue 2;
                    }
                }

                $seen_delivery_options[] = $candidate;
                $unique_orders_ids[]     = (int) $order_id;
            }

            // Страница пришла неполной — история заказов исчерпана
            if (count($orders_ids) < $page_size) {
                break;
            }

            $offset    += $page_size;
            $page_size = min($page_size * 2, self::HISTORY_PAGE_MAX);
        }

        return $unique_orders_ids;
    }

    /**
     * Формирует параметры предзаполнения из параметров чекаута
     *
     * Извлекает данные о регионе, доставке, адресе, оплате, комментарии и авторизации
     * из структуры параметров чекаута Shop-Script
     *
     * @param array $checkout_params Параметры чекаута из waCheckout
     * @return shopPrefillPluginFillParams Параметры предзаполнения
     */
    public function getFillParamsByCheckoutParams(array $checkout_params): shopPrefillPluginFillParams
    {
        $fill_params = new shopPrefillPluginFillParams();

        // Получаем данные об авторизации (для неавторизованных пользователей)
        $auth_params = $checkout_params['order']['auth'] ?? [];
        if ($auth_params) {
            // Тип покупателя (person/company)
            if (isset($auth_params['mode'])) {
                $fill_params->setCustomerType($auth_params['mode']);
            }

            // Поля auth[data] (email, phone, кастомные поля)
            if (isset($auth_params['data']) && is_array($auth_params['data'])) {
                $fill_params->setAuthData($auth_params['data']);
            }
        }

        // Получаем данные о регионе
        $region_params = $checkout_params['order']['region'] ?? [];
        if ($region_params) {
            if (isset($region_params['country'])) {
                $fill_params->setCountry($region_params['country']);
            }

            if (isset($region_params['region'])) {
                $fill_params->setRegion($region_params['region']);
            }

            if (isset($region_params['city'])) {
                $fill_params->setCity($region_params['city']);
            }

            if (isset($region_params['zip'])) {
                $fill_params->setZip($region_params['zip']);
            }
        }

        // Получаем данные о доставке. Вариант — единственная идентичность выбора;
        // type_id в сессии ядра больше не читаем (ядро сверяет только variant_id,
        // shopCheckoutShippingStep:226-234).
        $shipping_params = $checkout_params['order']['shipping'] ?? [];
        if ($shipping_params && isset($shipping_params['variant_id'])) {
            $fill_params->setShippingVariantId($shipping_params['variant_id']);
        }

        // Получаем данные о деталях доставки (адрес и кастомные поля)
        $shipping_address_params = $checkout_params['order']['details']['shipping_address'] ?? [];
        if ($shipping_address_params) {
            $standard_address_fields = ['country', 'region', 'city', 'zip', 'street'];

            // Извлекаем стандартные поля
            if (isset($shipping_address_params['street'])) {
                $fill_params->setStreet($shipping_address_params['street']);
            }

            // zip может быть в details вместо region (зависит от настройки администратора).
            // Устанавливаем только если ещё не было установлено из секции region.
            if (! $fill_params->getZip() && isset($shipping_address_params['zip'])) {
                $fill_params->setZip($shipping_address_params['zip']);
            }

            // Всё остальное — кастомные поля (building, apartment, podezd, floor, и т.д.)
            $custom_address_fields = array_diff_key(
                $shipping_address_params,
                array_flip($standard_address_fields)
            );
            if (! empty($custom_address_fields)) {
                $fill_params->setShippingAddressCustom($custom_address_fields);
            }
        }

        // Получаем данные об оплате
        $payment_params = $checkout_params['order']['payment'] ?? [];
        if ($payment_params) {
            if (isset($payment_params['id'])) {
                $fill_params->setPaymentId($payment_params['id']);
            }

            if (isset($payment_params['custom'])) {
                $fill_params->setPaymentCustom($payment_params['custom']);
            }
        }

        // Получаем данные о подтверждении
        $confirm_params = $checkout_params['order']['confirm'] ?? [];
        if (isset($confirm_params['comment'])) {
            $fill_params->setComment($confirm_params['comment']);
        }

        return $fill_params;
    }

    /**
     * Формирует параметры предзаполнения из параметров заказа и контакта
     *
     * Преобразует данные заказа из базы данных в объект параметров предзаполнения.
     * Обогащает данные названиями стран и регионов через LocationProvider.
     * Добавляет данные auth секции из контакта через ContactProvider.
     *
     * @param array $order_params Параметры заказа из базы данных
     * @param int|null $order_id ID заказа для идентификации набора параметров
     * @return shopPrefillPluginFillParams Параметры предзаполнения
     */
    public function getFillParamsByOrderParams(array $order_params, int $order_id = null): shopPrefillPluginFillParams
    {
        $fill_params = new shopPrefillPluginFillParams();

        if ($order_id) {
            $fill_params->setId($order_id);
        }

        // Страна доставки
        if (isset($order_params['shipping_address.country'])) {
            $fill_params->setCountry($order_params['shipping_address.country']);

            $country_name = $this->location_provider->getCountryName($order_params['shipping_address.country']);
            $fill_params->setCountryName($country_name);
        }

        // Регион доставки
        if (isset($order_params['shipping_address.region'])) {
            $fill_params->setRegion($order_params['shipping_address.region']);

            $region_name = $this->location_provider->getRegionName(
                $order_params['shipping_address.country'] ?? null,
                $order_params['shipping_address.region']
            );
            $fill_params->setRegionName($region_name);
        }

        // Город доставки
        if (isset($order_params['shipping_address.city'])) {
            $fill_params->setCity($order_params['shipping_address.city']);
        }

        // Индекс
        if (isset($order_params['shipping_address.zip'])) {
            $fill_params->setZip($order_params['shipping_address.zip']);
        }

        // Улица
        if (isset($order_params['shipping_address.street'])) {
            $fill_params->setStreet($order_params['shipping_address.street']);
        }

        // Кастомные поля адреса доставки (building, apartment, podezd, floor, и т.д.)
        $standard_address_suffixes = ['country', 'region', 'city', 'zip', 'street'];
        $custom_address_fields     = [];
        foreach ($order_params as $key => $value) {
            if (strpos($key, 'shipping_address.') === 0) {
                $field = substr($key, strlen('shipping_address.'));
                if (! in_array($field, $standard_address_suffixes, true)) {
                    $custom_address_fields[$field] = $value;
                }
            }
        }
        if (! empty($custom_address_fields)) {
            $fill_params->setShippingAddressCustom($custom_address_fields);
        }

        // Параметры доставки
        if (isset($order_params['shipping_id'])) {
            $fill_params->setShippingId((int) $order_params['shipping_id']);
        }
        if (isset($order_params['shipping_rate_id'])) {
            $fill_params->setShippingRateId($order_params['shipping_rate_id']);
        }

        if (isset($order_params['shipping_name'])) {
            $fill_params->setShippingName($order_params['shipping_name']);
        }

        // Кастомные параметры доставки
        $shipping_params = shopPrefillPluginFillParamsHelper::filteredOrderParams($order_params, 'shipping_params_');
        if (! empty($shipping_params)) {
            $fill_params->setShippingCustom($shipping_params);
        }

        // Кастомные параметры оплаты
        $payment_params = shopPrefillPluginFillParamsHelper::filteredOrderParams($order_params, 'payment_params_');
        if (! empty($payment_params)) {
            $fill_params->setPaymentCustom($payment_params);
        }
        if (isset($order_params['payment_id'])) {
            $fill_params->setPaymentId((int) $order_params['payment_id']);
        }
        if (isset($order_params['payment_name'])) {
            $fill_params->setPaymentName($order_params['payment_name']);
        }
        if (isset($order_params['payment_plugin'])) {
            $fill_params->setPaymentPlugin($order_params['payment_plugin']);
        }

        // shipping_plugin в shop_order_params лежит, но НЕ заполняется намеренно:
        // он входит в $shipping_params, по которым сравнивает isSameDeliveryOption(),
        // а вторая сторона сравнения (getFillParamsByCheckoutParams) взять его неоткуда
        // — в сессии чекаута только type_id и variant_id. Заполнение здесь сделало бы
        // сравнение асимметричным и погасило бы is_current в выборе адреса.

        // Комментарий читаем напрямую из shop_order — единый источник истины.
        // Это позволяет подхватить правки, сделанные администратором в бэкенде.
        if ($order_id) {
            $comment = $this->order_provider->getOrderComment($order_id);
            if ($comment !== null && $comment !== '') {
                $fill_params->setComment($comment);
            }
        }

        // Auth данные из контакта
        if ($order_id) {
            $this->fillAuthDataFromOrder($fill_params, $order_id);
        }

        return $fill_params;
    }

    /**
     * Заполняет auth данные из контакта заказа
     *
     * @param shopPrefillPluginFillParams $fill_params Объект параметров для заполнения
     * @param int $order_id ID заказа
     */
    private function fillAuthDataFromOrder(shopPrefillPluginFillParams $fill_params, int $order_id): void
    {
        $contact_id = $this->order_provider->getContactIdFromOrder($order_id);
        if (! $contact_id) {
            return;
        }

        $contact = $this->contact_provider->getContact($contact_id);
        if (! $contact) {
            return;
        }

        // Тип покупателя
        $customer_type = $this->contact_provider->getCustomerType($contact);
        $fill_params->setCustomerType($customer_type);

        // Все поля auth[data]
        $auth_data = $this->contact_provider->getAuthData($contact);
        $fill_params->setAuthData($auth_data);
    }
}
