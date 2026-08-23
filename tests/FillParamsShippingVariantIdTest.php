<?php

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

$variants = [
    '5.pickup.MSK123'  => [5, 'pickup.MSK123'],
    '12.cdek.PVZ-4419' => [12, 'cdek.PVZ-4419'],
    '3.courier'         => [3, 'courier'],
    // Самовывоз: rate_id === '0' — легитимное значение, не путать с пустотой (empty('0') === true).
    '37.0'              => [37, '0'],
];

foreach ($variants as $variant_id => $expected) {
    $params = new shopPrefillPluginFillParams();
    $params->setShippingVariantId($variant_id);

    assertSameValue($expected[0], $params->getShippingId(), $variant_id . ' shipping ID');
    assertSameValue($expected[1], $params->getShippingRateId(), $variant_id . ' rate ID');
    assertSameValue($variant_id, $params->getShippingVariantId(), $variant_id . ' round trip');
}

foreach (['', '5', '.pickup', '5.'] as $variant_id) {
    $params = new shopPrefillPluginFillParams();
    $params->setShippingVariantId($variant_id);

    assertSameValue(null, $params->getShippingId(), $variant_id . ' malformed shipping ID');
    assertSameValue(null, $params->getShippingRateId(), $variant_id . ' malformed rate ID');
}

echo "FillParamsShippingVariantIdTest: OK\n";
