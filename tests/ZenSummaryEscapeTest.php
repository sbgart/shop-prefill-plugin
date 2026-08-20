<?php

require_once dirname(__DIR__) . '/lib/classes/zenmode/shopPrefillPluginZenSummaryEscaper.class.php';

/**
 * Правило Z7: данные покупателя выводятся экранированными.
 *
 * Шаблон сводки пишет магазин и хранит его в БД, поэтому `|escape` в дефолтных шаблонах
 * защищает только свежую установку. Экранирование живёт в данных — здесь проверяется,
 * что оно накрывает всё, кроме полей с контрактом HTML, и не портит типы.
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

// Белый список повторяет флаги is_html из shopPrefillPluginZenData::getAvailableFields()
$escaper = new shopPrefillPluginZenSummaryEscaper([
    'shipping_rate',
    'delivery_schedule',
    'delivery_photos_html',
    'delivery_description',
    'payment_description',
    'service_agreement_hint',
]);

// ---------------------------------------------------------------------------
// 1. Скалярные поля покупателя
// ---------------------------------------------------------------------------

$result = $escaper->escape([
    'firstname' => '<img src=x onerror=alert(1)>',
    'street'    => 'Ленина, 1 & 2 "корпус" <b>жирный</b>',
    'company'   => "O'Reilly",
    'city'      => '',
]);

assertSameValue(
    '&lt;img src=x onerror=alert(1)&gt;',
    $result['firstname'],
    'тег в имени превращается в текст'
);
assertSameValue(
    'Ленина, 1 &amp; 2 &quot;корпус&quot; &lt;b&gt;жирный&lt;/b&gt;',
    $result['street'],
    'кавычки и амперсанд в адресе экранируются (ENT_QUOTES — вывод безопасен и в атрибуте)'
);
assertSameValue('O&#039;Reilly', $result['company'], 'одинарная кавычка экранируется');
assertSameValue('', $result['city'], 'пустая строка остаётся пустой — иначе поплывут {if} и hasFreshData()');

// ---------------------------------------------------------------------------
// 2. Кастомные поля: ключ выводится циклом наравне со значением
// ---------------------------------------------------------------------------

$result = $escaper->escape([
    'contact_custom' => [
        '<script>alert(1)</script>' => '<b>значение</b>',
    ],
    'delivery_photos' => [
        ['uri' => 'https://host/p.jpg?a=1&b=2', 'thumb_uri' => '"><script>'],
    ],
    'shipping_custom' => [],
]);

assertSameValue(
    ['&lt;script&gt;alert(1)&lt;/script&gt;' => '&lt;b&gt;значение&lt;/b&gt;'],
    $result['contact_custom'],
    'в кастомных полях экранируются и ключ, и значение'
);
assertSameValue(
    'https://host/p.jpg?a=1&amp;b=2',
    $result['delivery_photos'][0]['uri'],
    'вложенные значения фотографий экранируются рекурсивно'
);
assertSameValue(
    '&quot;&gt;&lt;script&gt;',
    $result['delivery_photos'][0]['thumb_uri'],
    'выход из атрибута src закрыт'
);
assertSameValue(0, key($result['delivery_photos']), 'числовые ключи списка не превращаются в строки');
assertSameValue([], $result['shipping_custom'], 'пустой массив остаётся пустым массивом');

// ---------------------------------------------------------------------------
// 3. Поля с контрактом HTML проходят нетронутыми
// ---------------------------------------------------------------------------

$html_values = [
    'shipping_rate'          => '<span class="prefill-zen-price">350 <span class="ruble">Р</span></span>',
    'delivery_schedule'      => '<div class="wa-day-wrapper"><div class="wa-date">пн</div></div>',
    'delivery_photos_html'   => '<div class="wa-photos-section" data-name="СДЭК"></div>',
    'delivery_description'   => 'Доставка <a href="/terms/">по правилам</a>',
    'payment_description'    => 'Оплата <b>онлайн</b>',
    'service_agreement_hint' => 'Согласен с <a href="/oferta/">офертой</a>',
];
$result = $escaper->escape($html_values);
foreach ($html_values as $key => $value) {
    assertSameValue($value, $result[$key], "поле {$key} остаётся HTML — паритет с выводом ядра");
}

// ---------------------------------------------------------------------------
// 4. Типы: не-строковые скаляры не приводятся к строке
// ---------------------------------------------------------------------------

$result = $escaper->escape([
    'delivery_storage_days' => 14,
    'shipping_rate_raw'     => 350.5,
    'is_current'            => true,
    'shipping_logo'         => null,
]);

assertSameValue(14, $result['delivery_storage_days'], 'int остаётся int');
assertSameValue(350.5, $result['shipping_rate_raw'], 'float остаётся float');
assertSameValue(true, $result['is_current'], 'bool остаётся bool');
assertSameValue(null, $result['shipping_logo'], 'null остаётся null');

// ---------------------------------------------------------------------------
// 5. Набор ключей не меняется — шаблон обращается ко всем переменным
// ---------------------------------------------------------------------------

$input = ['firstname' => 'Иван', 'lastname' => '', 'contact_custom' => [], 'shipping_rate' => '<b>0</b>'];
assertSameValue(
    array_keys($input),
    array_keys($escaper->escape($input)),
    'состав и порядок ключей сохраняются'
);

echo "OK: ZenSummaryEscapeTest\n";
