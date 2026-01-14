<?php

/**
 * Провайдер параметров предзаполнения чекаута
 *
 * Отвечает за получение параметров предзаполнения из БД:
 * - Для авторизованных: по contact_id из последнего заказа
 * - Для гостей: по prefill_guest_hash из последнего заказа с этим хешем
 */
class shopPrefillPluginFillParamsProvider
{
    private shopPrefillPluginOrderProvider    $order_provider;
    private shopPrefillPluginUserProvider     $user_provider;
    private shopPrefillPluginContactProvider  $contact_provider;
    private shopPrefillPluginGuestHashStorage $guest_hash_storage;
    private shopPrefillPluginLocationProvider $location_provider;
    private waResponse                        $response;

    /** @var shopPrefillPluginFillParamsCollection|null Коллекция параметров предзаполнения */
    private ?shopPrefillPluginFillParamsCollection $fill_params_collection = null;

    public function __construct(
        shopPrefillPluginOrderProvider $order_provider,
        shopPrefillPluginUserProvider $user_provider,
        shopPrefillPluginContactProvider $contact_provider,
        shopPrefillPluginGuestHashStorage $guest_hash_storage,
        shopPrefillPluginLocationProvider $location_provider,
        waResponse $response
    ) {
        $this->order_provider     = $order_provider;
        $this->user_provider      = $user_provider;
        $this->contact_provider   = $contact_provider;
        $this->guest_hash_storage = $guest_hash_storage;
        $this->location_provider  = $location_provider;
        $this->response           = $response;
    }

    /** Возвращает провайдер заказов */
    private function getOrderProvider(): shopPrefillPluginOrderProvider
    {
        return $this->order_provider;
    }

    /** Возвращает провайдер пользователя */
    private function getUserProvider(): shopPrefillPluginUserProvider
    {
        return $this->user_provider;
    }

    /** Возвращает хранилище хеша гостя */
    private function getGuestHashStorage(): shopPrefillPluginGuestHashStorage
    {
        return $this->guest_hash_storage;
    }

    /** Возвращает провайдер локаций (стран/регионов) */
    private function getLocationProvider(): shopPrefillPluginLocationProvider
    {
        return $this->location_provider;
    }

    /** Возвращает провайдер контактов */
    private function getContactProvider(): shopPrefillPluginContactProvider
    {
        return $this->contact_provider;
    }

    /** Возвращает объект ответа Webasyst */
    private function getResponse(): waResponse
    {
        return $this->response;
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
        // Авторизованные пользователи: данные из БД по contact_id
        if ($this->getUserProvider()->isAuth()) {
            return $this->getFillParamsForAuthorized($fill_params_id);
        }

        // Неавторизованные: данные из БД по хешу гостя
        return $this->getFillParamsForGuest();
    }

    /**
     * Получает параметры предзаполнения для авторизованного пользователя
     *
     * @param int|null $order_id Конкретный ID заказа (или null для последнего)
     * @return shopPrefillPluginFillParams
     */
    private function getFillParamsForAuthorized(?int $order_id = null): shopPrefillPluginFillParams
    {
        $contact_id = $this->getUserProvider()->getId();

        // Если указан конкретный заказ — используем его
        if ($order_id) {
            $order_params = $this->getOrderProvider()->getOrderParams($order_id);
            if ($order_params) {
                return $this->getFillParamsByOrderParams($order_params, $order_id);
            }
        }

        // Иначе берем последний заказ пользователя
        $last_order_id = $this->getOrderProvider()->getLastOrderIdByContactId($contact_id);

        if (! $last_order_id) {
            return new shopPrefillPluginFillParams();
        }

        $order_params = $this->getOrderProvider()->getOrderParams($last_order_id);

        if (! $order_params) {
            return new shopPrefillPluginFillParams();
        }

        return $this->getFillParamsByOrderParams($order_params, $last_order_id);
    }

    /**
     * Получает параметры предзаполнения для гостя (неавторизованного)
     *
     * @return shopPrefillPluginFillParams
     */
    private function getFillParamsForGuest(): shopPrefillPluginFillParams
    {
        // Создаем/получаем хеш гостя из куки (автопродлевается)
        $guest_hash = $this->getGuestHashStorage()->getOrCreateGuestHash();

        // Ищем последний заказ с этим хешем через OrderProvider
        $order_id = $this->getOrderProvider()->getLastOrderIdByGuestHash($guest_hash);

        if (! $order_id) {
            // Нет заказов с этим хешем — возвращаем пустой объект
            return new shopPrefillPluginFillParams();
        }

        $order_params = $this->getOrderProvider()->getOrderParams($order_id);

        if (! $order_params) {
            return new shopPrefillPluginFillParams();
        }

        return $this->getFillParamsByOrderParams($order_params, $order_id);
    }

    /**
     * Получает коллекцию всех доступных параметров предзаполнения
     *
     * Формирует коллекцию на основе всех заказов пользователя,
     * удаляя дубликаты по параметрам доставки.
     *
     * Для авторизованных: все заказы по contact_id
     * Для гостей: все заказы по prefill_guest_hash
     *
     * @return shopPrefillPluginFillParamsCollection Коллекция параметров предзаполнения
     */
    public function getFillParamsCollection(): shopPrefillPluginFillParamsCollection
    {
        if ($this->fill_params_collection) {
            return $this->fill_params_collection;
        }

        $this->fill_params_collection = new shopPrefillPluginFillParamsCollection();

        // Получаем список ID заказов в зависимости от типа пользователя
        if ($this->getUserProvider()->isAuth()) {
            $orders_ids = $this->getOrderProvider()->getUserOrdersId($this->getUserProvider()->getId());
        } else {
            $guest_hash = $this->getGuestHashStorage()->getOrCreateGuestHash();
            $orders_ids = $this->getOrderProvider()->getAllOrderIdsByGuestHash($guest_hash);
        }

        if (empty($orders_ids)) {
            return $this->fill_params_collection;
        }

        // Получаем параметры всех заказов
        $orders_params = [];
        foreach ($orders_ids as $order_id) {
            $params = $this->getOrderProvider()->getOrderParams($order_id);
            if ($params) {
                $orders_params[$order_id] = $params;
            }
        }

        // Удаляем дубликаты по параметрам доставки
        $unique_orders_params = shopPrefillPluginFillParamsHelper::removeDuplicateSubarrays(
            $orders_params,
            "shipping_"
        );

        foreach ($unique_orders_params as $order_id => $order_params) {
            $fill_params = $this->getFillParamsByOrderParams($order_params, $order_id);
            $this->fill_params_collection->add($fill_params);
        }

        return $this->fill_params_collection;
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

        // Получаем данные о доставке
        $shipping_params = $checkout_params['order']['shipping'] ?? [];
        if ($shipping_params) {
            if (isset($shipping_params['type_id'])) {
                $fill_params->setShippingTypeId($shipping_params['type_id']);
            }

            if (isset($shipping_params['variant_id'])) {
                $fill_params->setShippingVariantId($shipping_params['variant_id']);
            }
        }

        // Получаем данные о деталях доставки
        $shipping_details_params = $checkout_params['order']['details'] ?? [];
        if ($shipping_details_params) {
            if (isset($shipping_details_params['shipping_address']['street'])) {
                $fill_params->setStreet($shipping_details_params['shipping_address']['street']);
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
        $confirm_params = $checkout_params['order']['payment'] ?? [];
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

            $country_name = $this->getLocationProvider()->getCountryName($order_params['shipping_address.country']);
            $fill_params->setCountryName($country_name);
        }

        // Регион доставки
        if (isset($order_params['shipping_address.region'])) {
            $fill_params->setRegion($order_params['shipping_address.region']);

            $region_name = $this->getLocationProvider()->getRegionName(
                $order_params['shipping_address.country'],
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

        // Параметры доставки
        if (isset($order_params['shipping_id'])) {
            $fill_params->setShippingId((int) $order_params['shipping_id']);
        }
        if (isset($order_params['shipping_rate_id'])) {
            $fill_params->setShippingRateId($order_params['shipping_rate_id']);
        }
        if (isset($order_params['shipping_type_id'])) {
            $fill_params->setShippingTypeId((int) $order_params['shipping_type_id']);
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

        // Комментарий к заказу
        if (isset($order_params['comment'])) {
            $fill_params->setComment($order_params['comment']);
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
        $contact_id = $this->getOrderProvider()->getContactIdFromOrder($order_id);
        if (! $contact_id) {
            return;
        }

        $contact = $this->getContactProvider()->getContact($contact_id);
        if (! $contact) {
            return;
        }

        // Тип покупателя
        $customer_type = $this->getContactProvider()->getCustomerType($contact);
        $fill_params->setCustomerType($customer_type);

        // Все поля auth[data]
        $auth_data = $this->getContactProvider()->getAuthData($contact);
        $fill_params->setAuthData($auth_data);
    }
}
