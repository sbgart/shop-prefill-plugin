<?php

require_once dirname(__DIR__) . '/lib/classes/fillparams/shopPrefillPluginFillParamsHelper.class.php';
require_once dirname(__DIR__) . '/lib/classes/fillparams/shopPrefillPluginFillParams.class.php';

/**
 * @param mixed  $expected
 * @param mixed  $actual
 * @param string $message
 */
function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

$cases = [
    // Самовывоз: rate_id === '0' — главная строка файла. empty('0') === true выбросил бы
    // ровно те заказы (52 из 85 на тестовой базе), ради которых вариант и стал единственной
    // идентичностью доставки.
    [['shipping_id' => '37', 'shipping_rate_id' => '0'], '37.0'],
    [['shipping_id' => '36', 'shipping_rate_id' => 'parcel'], '36.parcel'],
    [['shipping_id' => '43', 'shipping_rate_id' => 'NSK2:136:270'], '43.NSK2:136:270'],
    [['shipping_id' => '5', 'shipping_rate_id' => 'pickup.MSK123'], '5.pickup.MSK123'],
    [['shipping_id' => '5'], null], // rate_id отсутствует
    [['shipping_id' => '5', 'shipping_rate_id' => ''], null], // rate_id пуст
    [['shipping_rate_id' => 'parcel'], null], // shipping_id отсутствует
    [['shipping_id' => '0', 'shipping_rate_id' => 'parcel'], null], // shipping_id === '0'
    [['shipping_id' => '', 'shipping_rate_id' => 'parcel'], null], // shipping_id пуст
    [['shipping_id' => 'abc', 'shipping_rate_id' => 'parcel'], null], // shipping_id не число
];

foreach ($cases as [$order_params, $expected]) {
    $actual = shopPrefillPluginFillParamsHelper::deliveryVariantId($order_params);
    assertSameValue($expected, $actual, 'deliveryVariantId(' . var_export($order_params, true) . ')');
}

// Страж дрейфа: результат хелпера обязан совпадать с FillParams::getShippingVariantId()
// для тех же входных данных — иначе гейт истории и объект предзаполнения разъедутся.
foreach ($cases as [$order_params, $expected]) {
    if ($expected === null) {
        continue;
    }

    $params = new shopPrefillPluginFillParams();
    if (isset($order_params['shipping_id'])) {
        $params->setShippingId((int) $order_params['shipping_id']);
    }
    if (isset($order_params['shipping_rate_id'])) {
        $params->setShippingRateId($order_params['shipping_rate_id']);
    }

    assertSameValue($expected, $params->getShippingVariantId(),
        'round-trip: deliveryVariantId() vs getShippingVariantId() для ' . $expected);
}

echo "FillParamsHelperDeliveryVariantIdTest: OK\n";
