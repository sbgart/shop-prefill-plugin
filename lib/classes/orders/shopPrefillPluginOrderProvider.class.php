<?php

class shopPrefillPluginOrderProvider
{
    private ?shopOrderModel       $order_model        = null;
    private ?shopOrderParamsModel $order_params_model = null;

    private ?array $last_user_order = null;

    public function __construct(shopOrderModel $order_model, shopOrderParamsModel $order_params_model)
    {
        $this->order_model        = $order_model;
        $this->order_params_model = $order_params_model;
    }

    private function getOrderModel(): ?shopOrderModel
    {
        return $this->order_model;
    }

    private function getOrderParamsModel(): ?shopOrderParamsModel
    {
        return $this->order_params_model;
    }

    public function getOrderParams(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $order_params = $this->getOrderParamsModel()->get($id);

        return empty($order_params) ? null : $order_params;
    }

    public function getLastOrderIdByContactId(int $contact_id): ?int
    {
        if ($contact_id <= 0) {
            return null;
        }
        $last_order_id = $this->getOrderModel()->select("*")
            ->where('contact_id=?', $contact_id)
            ->order('id DESC')->fetchField();

        return $last_order_id !== false ? $last_order_id : null;
    }

    public function getUserOrdersId(int $contact_id): ?array
    {
        if ($contact_id <= 0) {
            return null;
        }

        $orders_id = $this->getOrderModel()->select("id")
            ->where('contact_id=?', $contact_id)
            ->order('id DESC')->fetchAll();

        return array_column($orders_id, 'id');
    }

    public function getUserOrdersParams(int $contact_id): array
    {
        if (empty($contact_id)) {
            return [];
        }

        $orders_id = $this->getUserOrdersId($contact_id);

        if (empty($orders_id)) {
            return [];
        }

        $params = $this->getOrderParamsModel()->get($orders_id);

        return $params ?: [];
    }


    public function storeShippingTypeId(int $order_id, int $shipping_type_id): bool
    {
        if ($shipping_type_id <= 0 && $order_id <= 0) {
            return false;
        }

        return $this->getOrderParamsModel()->setOne($order_id, 'shipping_type_id', $shipping_type_id);
    }

    public function getOrderComment(int $order_id): ?string
    {
        if ($order_id <= 0) {
            return null;
        }

        $comment = $this->getOrderModel()->select("*")->where('id=?', $order_id)->fetchField('comment');

        return $comment !== false ? $comment : '';
    }

    public function storeComment(int $order_id, ?string $comment): bool
    {
        if (empty($comment) || $order_id <= 0) {
            return false;
        }

        return $this->getOrderParamsModel()->setOne($order_id, 'comment', $comment);
    }

    /**
     * Получает contact_id из заказа
     *
     * @param int $order_id ID заказа
     * @return int|null contact_id или null
     */
    public function getContactIdFromOrder(int $order_id): ?int
    {
        if ($order_id <= 0) {
            return null;
        }

        $contact_id = $this->getOrderModel()->select('contact_id')
            ->where('id=?', $order_id)
            ->fetchField('contact_id');

        return $contact_id ? (int) $contact_id : null;
    }

    /**
     * Находит ID последнего заказа по хешу гостя
     *
     * @param string $hash Хеш гостя
     * @return int|null ID последнего заказа или null если не найден
     */
    public function getLastOrderIdByGuestHash(string $hash): ?int
    {
        if (empty($hash)) {
            return null;
        }

        $result = $this->getOrderParamsModel()
            ->query(
                "SELECT order_id FROM shop_order_params
                 WHERE name = s:name AND value = s:hash
                 ORDER BY order_id DESC
                 LIMIT 1",
                [
                    'name' => shopPrefillPluginGuestHashStorage::getGuestHashParamName(),
                    'hash' => $hash,
                ]
            )
            ->fetchField('order_id');

        return $result ? (int) $result : null;
    }

    /**
     * Возвращает все ID заказов гостя по хешу
     *
     * @param string $hash Хеш гостя
     * @return array Массив ID заказов (от новых к старым)
     */
    public function getAllOrderIdsByGuestHash(string $hash): array
    {
        if (empty($hash)) {
            return [];
        }

        $results = $this->getOrderParamsModel()
            ->query(
                "SELECT order_id FROM shop_order_params
                 WHERE name = s:name AND value = s:hash
                 ORDER BY order_id DESC",
                [
                    'name' => shopPrefillPluginGuestHashStorage::getGuestHashParamName(),
                    'hash' => $hash,
                ]
            )
            ->fetchAll('order_id');

        return array_keys($results);
    }

}
