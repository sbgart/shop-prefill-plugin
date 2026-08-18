<?php

class shopPrefillPluginOrderProvider
{
    /** Лимит истории для авторизованной коллекции и служебной диагностики */
    private const HISTORY_LIMIT = 50;

    private shopOrderModel       $order_model;
    private shopOrderParamsModel $order_params_model;

    /**
     * Кэш строк shop_order на время запроса.
     *
     * Статический намеренно: waEvent пересоздаёт объект плагина на каждый хук
     * (см. issue-73), поэтому поле экземпляра не пережило бы даже одну загрузку /order/,
     * где срабатывают и frontend_head, и checkout_before_auth.
     *
     * @var array<int, array|null>
     */
    private static array $order_rows = [];

    public function __construct(shopOrderModel $order_model, shopOrderParamsModel $order_params_model)
    {
        $this->order_model        = $order_model;
        $this->order_params_model = $order_params_model;
    }

    public function getOrderParams(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $order_params = $this->order_params_model->get($id);

        return empty($order_params) ? null : $order_params;
    }

    /**
     * Одна строка заказа с полями, которые нужны предзаполнению.
     *
     * Раньше comment и contact_id читались двумя отдельными запросами к одной и той же строке.
     *
     * @return array{id:int, contact_id:?int, comment:?string}|null
     */
    private function getOrderRow(int $order_id): ?array
    {
        if ($order_id <= 0) {
            return null;
        }

        if (array_key_exists($order_id, self::$order_rows)) {
            return self::$order_rows[$order_id];
        }

        $row = $this->order_model->select('id, contact_id, comment')
            ->where('id=?', $order_id)
            ->fetchAssoc();

        return self::$order_rows[$order_id] = ($row ?: null);
    }

    /**
     * ID последнего заказа покупателя.
     *
     * Источник — строка shop_customer по первичному ключу: ядро поддерживает её в
     * shopCustomerModel::updateFromNewOrder(). Это снимает сортировку по всем заказам контакта.
     *
     * Пустота проверяется по last_order_id, а НЕ по number_of_orders: строка shop_customer
     * создаётся и без заказов (createFromContact()), а last_order_id — nullable.
     */
    public function getLastOrderIdByContactId(int $contact_id): ?int
    {
        if ($contact_id <= 0) {
            return null;
        }

        $last_order_id = $this->order_model->query(
            "SELECT last_order_id FROM shop_customer WHERE contact_id = i:contact_id LIMIT 1",
            ['contact_id' => $contact_id]
        )->fetchField('last_order_id');

        return $last_order_id ? (int) $last_order_id : null;
    }

    /**
     * ID заказов покупателя, от новых к старым.
     *
     * @param int $contact_id
     * @param int $limit Лимит применяется в БД, до гидратации (issue-68)
     */
    public function getUserOrdersId(int $contact_id, int $limit = self::HISTORY_LIMIT): array
    {
        if ($contact_id <= 0) {
            return [];
        }

        $orders_id = $this->order_model->select("id")
            ->where('contact_id=?', $contact_id)
            ->order('id DESC')
            ->limit($limit)
            ->fetchAll();

        return array_column($orders_id, 'id');
    }

    /**
     * Возвращает параметры заказов по массиву ID — один запрос вместо N.
     *
     * @param array $order_ids
     * @return array [order_id => [name => value]]
     */
    public function getOrdersParamsByIds(array $order_ids): array
    {
        if (empty($order_ids)) {
            return [];
        }

        return $this->order_params_model->get($order_ids) ?: [];
    }

    public function storeShippingTypeId(int $order_id, string $shipping_type_id): bool
    {
        if (empty($shipping_type_id) || $order_id <= 0) {
            return false;
        }

        return $this->order_params_model->setOne($order_id, 'shipping_type_id', $shipping_type_id);
    }

    public function getOrderComment(int $order_id): ?string
    {
        $row = $this->getOrderRow($order_id);

        return $row === null ? null : (string) ($row['comment'] ?? '');
    }

    /**
     * Получает contact_id из заказа
     *
     * @param int $order_id ID заказа
     * @return int|null contact_id или null
     */
    public function getContactIdFromOrder(int $order_id): ?int
    {
        $row = $this->getOrderRow($order_id);

        return empty($row['contact_id']) ? null : (int) $row['contact_id'];
    }

    /**
     * ID последнего заказа гостя по имени параметра.
     *
     * Точное равенство по существующему индексу `name`: в сортировку попадают
     * только заказы этого гостя, а не вся гостевая история магазина.
     *
     * @param string $param_name Имя вида prefill_guest_<48 hex>, см. GuestTokenStorage
     */
    public function getLastOrderIdByGuestParam(string $param_name): ?int
    {
        if ($param_name === '') {
            return null;
        }

        $result = $this->order_params_model
            ->query(
                "SELECT order_id FROM shop_order_params
                 WHERE name = s:name
                 ORDER BY order_id DESC
                 LIMIT 1",
                ['name' => $param_name]
            )
            ->fetchField('order_id');

        return $result ? (int) $result : null;
    }

    /**
     * ID заказов гостя, от новых к старым. Лимит применяется в БД (issue-68).
     *
     * @param string $param_name Имя вида prefill_guest_<48 hex>
     * @param int    $limit
     */
    public function getOrderIdsByGuestParam(string $param_name, int $limit = self::HISTORY_LIMIT): array
    {
        if ($param_name === '') {
            return [];
        }

        $results = $this->order_params_model
            ->query(
                "SELECT order_id FROM shop_order_params
                 WHERE name = s:name
                 ORDER BY order_id DESC
                 LIMIT i:limit",
                ['name' => $param_name, 'limit' => $limit]
            )
            ->fetchAll('order_id');

        return array_keys($results);
    }
}
