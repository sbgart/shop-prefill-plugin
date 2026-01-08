<?php

class shopPrefillPluginOrderProvider
{
    private ?shopOrderModel $order_model = null;
    private ?shopOrderParamsModel $order_params_model = null;

    private ?array $last_user_order = null;

    public function __construct(shopOrderModel $order_model, shopOrderParamsModel $order_params_model)
    {
        $this->order_model = $order_model;
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

    public function getUserOrdersParams(int $contact_id): ?array
    {
        if (empty($contact_id)) {
            return null;
        }

        $orders_id = $this->getUserOrdersId($contact_id);

        return $this->getOrderParamsModel()->get($orders_id);
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

    public function storeComment(int $order_id, string $comment): bool
    {
        if (empty($comment) || $order_id <= 0) {
            return false;
        }

        return $this->getOrderParamsModel()->setOne($order_id, 'comment', $comment);
    }

}
