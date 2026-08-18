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

/**
 * "Полный" вариант доставки без пустых полей — точка отсчёта, от которой
 * тесты отклоняют ровно одно поле за раз.
 */
function makeFullDeliveryOption(): shopPrefillPluginFillParams
{
    $params = new shopPrefillPluginFillParams();

    $params->setCountry('ru');
    $params->setRegion('77');
    $params->setCity('Москва');
    $params->setZip('101000');
    $params->setStreet('Тверская 1');

    $params->setShippingId(5);
    $params->setShippingTypeId('pickup');
    $params->setShippingRateId('MSK123');
    $params->setShippingName('Самовывоз'); // должно игнорироваться сравнением
    $params->setShippingPlugin('cdek');
    $params->setShippingCustom(['point_id' => 'PVZ-4419']);

    $params->setShippingAddressCustom(['entrance' => '3', 'floor' => '5']);

    return $params;
}

function cloneWith(shopPrefillPluginFillParams $base, callable $mutator): shopPrefillPluginFillParams
{
    $clone = clone $base;
    $mutator($clone);

    return $clone;
}

/**
 * Мини-симуляция цикла дедупликации из getFillParamsCollection(): кандидаты
 * подаются от новых к старым, как в реальном обходе array_reverse($orders_params, true).
 * Возвращает НЕ отброшенные как дубли варианты, в том же порядке (новые -> старые).
 *
 * @param shopPrefillPluginFillParams[] $candidates_newest_first
 * @return shopPrefillPluginFillParams[]
 */
function simulateDedup(array $candidates_newest_first): array
{
    $seen = [];
    $kept = [];

    foreach ($candidates_newest_first as $candidate) {
        $is_duplicate = false;

        foreach ($seen as $seen_item) {
            if ($candidate->isSameDeliveryOption($seen_item)) {
                $is_duplicate = true;
                break;
            }
        }

        if (! $is_duplicate) {
            $seen[] = $candidate;
            $kept[] = $candidate;
        }
    }

    return $kept;
}

$full = makeFullDeliveryOption();

// 1. null и непустое значение дают false в обоих направлениях
$without_street = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setStreet(null);
});

assertSameValue(false, $without_street->isSameDeliveryOption($full), 'null street vs filled street (this=null)');
assertSameValue(false, $full->isSameDeliveryOption($without_street), 'filled street vs null street (this=filled)');

// 2. null и '' считаются одинаковой пустотой
$null_street  = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setStreet(null);
});
$empty_street = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setStreet('');
});

assertSameValue(true, $null_street->isSameDeliveryOption($empty_street), 'null street vs empty-string street (this=null)');
assertSameValue(true, $empty_street->isSameDeliveryOption($null_street), 'empty-string street vs null street (this=empty)');

// 3. полностью одинаковые варианты дают true; shipping_name не участвует в сравнении
$same = clone $full;
assertSameValue(true, $full->isSameDeliveryOption($same), 'identical variants');
assertSameValue(true, $same->isSameDeliveryOption($full), 'identical variants (reversed)');

$different_name = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setShippingName('Другое текстовое описание');
});
assertSameValue(true, $full->isSameDeliveryOption($different_name), 'shipping_name is ignored by comparison');

// 4. разные street, zip, shipping_id, shipping_type_id, shipping_rate_id дают false
$fields_to_diff = [
    'street'           => ['setStreet', 'Другая улица'],
    'zip'              => ['setZip', '999999'],
    'shipping_id'      => ['setShippingId', 999],
    'shipping_type_id' => ['setShippingTypeId', 'courier'],
    'shipping_rate_id' => ['setShippingRateId', 'OTHER'],
];

foreach ($fields_to_diff as $field => [$setter, $diff_value]) {
    $variant = cloneWith($full, static function (shopPrefillPluginFillParams $p) use ($setter, $diff_value) {
        $p->$setter($diff_value);
    });

    assertSameValue(false, $full->isSameDeliveryOption($variant), "different {$field} (this=full)");
    assertSameValue(false, $variant->isSameDeliveryOption($full), "different {$field} (this=variant)");
}

// 5. одинаковые массивы с разным порядком ключей дают true (shipping_custom и shipping_address_custom)
$custom_order_a = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setShippingCustom(['a' => '1', 'b' => '2']);
});
$custom_order_b = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setShippingCustom(['b' => '2', 'a' => '1']);
});
assertSameValue(true, $custom_order_a->isSameDeliveryOption($custom_order_b), 'shipping_custom: same array, different key order');

$address_custom_order_a = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setShippingAddressCustom(['entrance' => '3', 'floor' => '5']);
});
$address_custom_order_b = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setShippingAddressCustom(['floor' => '5', 'entrance' => '3']);
});
assertSameValue(true, $address_custom_order_a->isSameDeliveryOption($address_custom_order_b), 'shipping_address_custom: same array, different key order');

// 6. массив и null дают false в обоих направлениях
$null_custom = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setShippingCustom(null);
});
assertSameValue(false, $full->isSameDeliveryOption($null_custom), 'array shipping_custom vs null (this=array)');
assertSameValue(false, $null_custom->isSameDeliveryOption($full), 'null shipping_custom vs array (this=null)');

// Дополнительно: 0 и null — разные значения; числовые строки '01' и '1' — разные значения
$zero_shipping_id = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setShippingId(0);
});
$null_shipping_id = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setShippingId(null);
});
assertSameValue(false, $zero_shipping_id->isSameDeliveryOption($null_shipping_id), '0 vs null shipping_id (this=0)');
assertSameValue(false, $null_shipping_id->isSameDeliveryOption($zero_shipping_id), 'null vs 0 shipping_id (this=null)');

$rate_01 = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setShippingRateId('01');
});
$rate_1 = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setShippingRateId('1');
});
assertSameValue(false, $rate_01->isSameDeliveryOption($rate_1), "'01' vs '1' shipping_rate_id (this='01')");
assertSameValue(false, $rate_1->isSameDeliveryOption($rate_01), "'1' vs '01' shipping_rate_id (this='1')");

// 8. точные дубли в цикле дедупликации схлопываются с сохранением самого нового
$newest           = clone $full;
$older_duplicate  = clone $full;
$kept             = simulateDedup([$newest, $older_duplicate]); // порядок: новые -> старые
assertSameValue(1, count($kept), 'exact duplicates collapse to one');
assertSameValue($newest, $kept[0], 'the newest of exact duplicates is the one kept');

// 9. старый вариант с отсутствующим полем не схлопывается с новым вариантом, где поле заполнено
$newer_with_street     = clone $full;
$older_without_street  = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setStreet(null);
});
$kept = simulateDedup([$newer_with_street, $older_without_street]); // новые -> старые
assertSameValue(2, count($kept), 'older variant with a missing field is preserved, not swallowed as a duplicate');

// 10. при частичном текущем состоянии чекаута не подсвечивается несколько разных карточек
$current_partial = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setStreet(null);
});

$saved_variant_a = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setStreet('Улица А');
});
$saved_variant_b = cloneWith($full, static function (shopPrefillPluginFillParams $p) {
    $p->setStreet('Улица Б');
});

$active_count = 0;
foreach ([$saved_variant_a, $saved_variant_b] as $saved_variant) {
    if ($saved_variant->isSameDeliveryOption($current_partial)) {
        $active_count++;
    }
}
assertSameValue(0, $active_count, 'partial current checkout does not falsely highlight any saved variant');

echo "FillParamsSameDeliveryOptionTest: OK\n";
