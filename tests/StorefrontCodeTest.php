<?php

require_once dirname(__DIR__) . '/lib/classes/storefronts/shopPrefillPluginStorefrontCode.class.php';

/**
 * Идентичность витрины держится на checkout_storefront_id, а не на её адресе: `url` маршрута
 * ядро переписывает молча («сделать главной страницей», переименование раздела или домена),
 * и на прежней схеме base64(domain/url) любое такое действие осиротевало все настройки витрины.
 * Тесты покрывают именно устойчивость кода к смене адреса и корректность фоллбэка для
 * легаси-маршрутов без id. Разбор — docs/plans/storefront-identity-by-checkout-id.md.
 *
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

function assertNotSameValue($unexpected, $actual, string $message): void
{
    if ($unexpected === $actual) {
        throw new RuntimeException(
            $message . ': got ' . var_export($actual, true) . ', which should differ'
        );
    }
}

$id      = 'ccce8c208c86784e78817b593a93faa5';
$other   = '4bed657311d6ac81b8e58fdee33d1a92';
$with_id = ['app' => 'shop', 'url' => '*', 'checkout_storefront_id' => $id];

// ---------------------------------------------------------------------------
// 1. Маршрут с checkout_storefront_id — код равен самому id
// ---------------------------------------------------------------------------

assertSameValue(
    $id,
    shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', '*', $with_id),
    'код витрины — это checkout_storefront_id маршрута'
);

// ---------------------------------------------------------------------------
// 2. Легаси-маршрут без id — прежняя схема base64(domain/url).
//    Такие маршруты id не получают даже задним числом, менять им код нельзя:
//    это осиротило бы их настройки тем самым фиксом, который чинит осиротение.
// ---------------------------------------------------------------------------

assertSameValue(
    base64_encode('wa-dev.loc/shop/*'),
    shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', 'shop/*', ['app' => 'shop']),
    'без id — фоллбэк на base64(domain/url)'
);

assertSameValue(
    base64_encode('wa-dev.loc/shop/*'),
    shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', 'shop/*'),
    'маршрут вообще не передан — тот же фоллбэк'
);

// ---------------------------------------------------------------------------
// 3. Пустой и пробельный id — это отсутствие id, а не код витрины.
//    Иначе все такие витрины схлопнулись бы в одну строку настроек.
// ---------------------------------------------------------------------------

$expected_fallback = base64_encode('wa-dev.loc/shop/*');

assertSameValue(
    $expected_fallback,
    shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', 'shop/*', ['checkout_storefront_id' => '']),
    'пустая строка в id — фоллбэк'
);

assertSameValue(
    $expected_fallback,
    shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', 'shop/*', ['checkout_storefront_id' => '   ']),
    'пробельный id — фоллбэк'
);

assertSameValue(
    $expected_fallback,
    shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', 'shop/*', ['checkout_storefront_id' => null]),
    'null в id — фоллбэк'
);

// ---------------------------------------------------------------------------
// 4. Глобальная витрина — '*', и маршрута у неё нет.
//    Ветка обязана срабатывать до обращения к маршруту.
// ---------------------------------------------------------------------------

assertSameValue(
    '*',
    shopPrefillPluginStorefrontCode::fromRoute('*', '*'),
    'глобальная витрина — код *'
);

assertSameValue(
    '*',
    shopPrefillPluginStorefrontCode::fromRoute('*', '*', $with_id),
    'глобальная витрина остаётся *, даже если в маршрут что-то положили'
);

// Домен '*' при конкретном url — не глобальная витрина
assertNotSameValue(
    '*',
    shopPrefillPluginStorefrontCode::fromRoute('*', 'shop/*'),
    'глобальной считается только пара * + *, а не один лишь домен'
);

// ---------------------------------------------------------------------------
// 5. Один id, разные url — один и тот же код. Это регресс на сам баг:
//    «сделать главной страницей» меняет url маршрута с 'shop/*' на '*',
//    checkout_storefront_id при этом не меняется.
// ---------------------------------------------------------------------------

assertSameValue(
    shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', 'shop/*', $with_id),
    shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', '*', $with_id),
    'смена url маршрута не меняет код витрины'
);

// ---------------------------------------------------------------------------
// 6. Один id, разные домены — тоже один код: переименование домена
//    не должно осиротить настройки
// ---------------------------------------------------------------------------

assertSameValue(
    shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', '*', $with_id),
    shopPrefillPluginStorefrontCode::fromRoute('shop-1.wa-dev.loc', '*', $with_id),
    'смена домена не меняет код витрины'
);

// ---------------------------------------------------------------------------
// 7. Разные id — разные коды, даже при полностью совпадающем адресе
// ---------------------------------------------------------------------------

assertNotSameValue(
    shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', '*', $with_id),
    shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', '*', ['checkout_storefront_id' => $other]),
    'разные витрины при одном адресе получают разные коды'
);

// Фоллбэк-код и id-код не пересекаются на реальных значениях
assertNotSameValue(
    shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', '*', $with_id),
    shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', '*'),
    'витрина с id и она же без id дают разные коды — схемы не смешиваются'
);

// ---------------------------------------------------------------------------
// 8. Код влезает в varchar(100) и безопасен как имя файла и как ключ POST-поля:
//    прежний base64 приносил '=', '+' и '/', md5 — только hex
// ---------------------------------------------------------------------------

$code = shopPrefillPluginStorefrontCode::fromRoute('wa-dev.loc', '*', $with_id);

assertSameValue(true, strlen($code) <= 100, 'код влезает в storefront_code varchar(100)');
assertSameValue(1, preg_match('/^[0-9a-f]{32}$/', $code), 'код по id — 32 hex-символа');

echo "StorefrontCodeTest: OK\n";
