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
     * ID заказов покупателя, от новых к старым, страницей.
     *
     * Страница нужна коллекции вариантов: она добирает историю, пока не наберёт нужное
     * число различных вариантов доставки (issue-68). Лимит применяется в БД, до гидратации.
     *
     * @param int $contact_id
     * @param int $limit  Размер страницы
     * @param int $offset Сколько заказов пропустить — уже просмотренные страницы
     */
    public function getUserOrdersId(int $contact_id, int $limit = self::HISTORY_LIMIT, int $offset = 0): array
    {
        if ($contact_id <= 0 || $limit <= 0) {
            return [];
        }

        $orders_id = $this->order_model
            ->query(
                "SELECT id FROM shop_order
                 WHERE contact_id = i:contact_id
                 ORDER BY id DESC
                 LIMIT i:offset, i:limit",
                ['contact_id' => $contact_id, 'offset' => max(0, $offset), 'limit' => $limit]
            )
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

    /**
     * Параметры, из которых складывается подпись варианта доставки — один запрос на страницу.
     *
     * Отличие от getOrdersParamsByIds(): тянет только префикс `shipping` (адрес доставки,
     * инстанс, тариф, кастомные поля плагина доставки) — ровно то, что сравнивает
     * FillParams::isSameDeliveryOption(). На живой базе это ~10 строк на заказ вместо ~28,
     * поэтому глубокий добор истории не стоит лишнего трафика (issue-68).
     *
     * @param array $order_ids
     * @return array [order_id => [name => value]]
     */
    public function getOrdersDeliveryParamsByIds(array $order_ids): array
    {
        $order_ids = array_filter(array_map('intval', $order_ids));
        if (empty($order_ids)) {
            return [];
        }

        $rows = $this->order_params_model
            ->query(
                "SELECT order_id, name, value FROM shop_order_params
                 WHERE order_id IN (i:ids) AND name LIKE 'shipping%'",
                ['ids' => $order_ids]
            )
            ->fetchAll();

        $params = [];
        foreach ($rows as $row) {
            $params[(int) $row['order_id']][$row['name']] = $row['value'];
        }

        return $params;
    }

    /**
     * Прогревает кэш строк shop_order одним запросом.
     *
     * Без него гидратация коллекции читает comment и contact_id по одному заказу за раз
     * (issue-17): getOrderRow() кэширует результат, но не избавляет от N запросов.
     *
     * @param array $order_ids
     */
    public function preloadOrderRows(array $order_ids): void
    {
        $order_ids = array_filter(array_map('intval', $order_ids));

        // Уже прочитанные заказы повторно не запрашиваем
        $missing = array_values(array_diff($order_ids, array_keys(self::$order_rows)));
        if (empty($missing)) {
            return;
        }

        $rows = $this->order_model
            ->query(
                "SELECT id, contact_id, comment FROM shop_order WHERE id IN (i:ids)",
                ['ids' => $missing]
            )
            ->fetchAll('id');

        foreach ($missing as $order_id) {
            self::$order_rows[$order_id] = $rows[$order_id] ?? null;
        }
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
