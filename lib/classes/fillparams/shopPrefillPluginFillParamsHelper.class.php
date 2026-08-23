<?php

final class shopPrefillPluginFillParamsHelper
{
    public static function filteredOrderParams(array $params, string $prefix): array
    {
        if ($prefix === '') {
            return [];
        }

        $result     = [];
        $prefix_len = strlen($prefix);

        foreach ($params as $param => $value) {
            if (strpos($param, $prefix) === 0) {
                $result[substr($param, $prefix_len)] = ! empty($value) ? $value : null;
            }
        }

        return $result;
    }

    /**
     * Идентичность варианта доставки прямо из параметров заказа: shipping_id + rate_id.
     *
     * Та же величина, что отдаёт shopPrefillPluginFillParams::getShippingVariantId(), —
     * считается до гидратации объекта, чтобы гейт истории и объект не разъехались.
     *
     * Пустоту различаем как helper::stripEmptyLeaves() (null/'' — пусто), а НЕ как
     * empty(): у самовывоза rate_id === '0' (52 заказа из 85 на тестовой базе, инстанс
     * «Пункт выдачи заказов»), и empty('0') === true выбросило бы ровно те заказы, ради
     * которых вариант и стал единственной идентичностью выбора доставки.
     * isValueFilled() в SectionChecker сознательно считает '0' незаполненным — там вопрос
     * «покупатель что-то выбрал?», здесь — «есть ли идентификатор».
     *
     * @param array $order_params [name => value] из shop_order_params
     * @return string|null null — у заказа нет варианта доставки
     */
    public static function deliveryVariantId(array $order_params): ?string
    {
        $shipping_id = isset($order_params['shipping_id']) ? (int) $order_params['shipping_id'] : 0;
        if ($shipping_id <= 0) {
            return null;
        }

        $rate_id = $order_params['shipping_rate_id'] ?? null;
        if ($rate_id === null || $rate_id === '') {
            return null;
        }

        return $shipping_id . '.' . $rate_id;
    }
}
