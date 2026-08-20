<?php

require_once dirname(__DIR__) . '/lib/classes/checkout/shopPrefillPluginCheckoutPageDetector.class.php';

/**
 * Правило A1: ассеты плагина подключаются только там, где рендерится форма заказа.
 * Детектор — единственное место, которое отвечает на вопрос «а тут форма есть?».
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

/**
 * @param array $route [module, action] — то, что отдал бы waRequest::param() на этом маршруте
 */
function makeDetector(array $route): shopPrefillPluginCheckoutPageDetector
{
    return new shopPrefillPluginCheckoutPageDetector(static function () use ($route) {
        return $route;
    });
}

// ---------------------------------------------------------------------------
// 1. Только по маршруту: чекаут-хуки ещё не срабатывали
// ---------------------------------------------------------------------------

$route_cases = [
    // [маршрут, ожидание, что защищаем]
    [['frontend', 'order'], true, 'страница оформления заказа checkout2 — маршрут frontend/order'],
    [['frontend', ''], false, 'главная витрины'],
    [['frontend', 'product'], false, 'карточка товара'],
    [['frontend', 'category'], false, 'страница категории'],
    [['frontend', 'search'], false, 'поиск'],
    [['frontend', 'checkout'], false, 'checkout/success/ — заказ уже создан, работать плагину нечем'],
    [['frontend', 'cart'], false, 'страница корзины checkout 1.x — формы checkout2 на ней нет'],
    [['frontend', 'myOrders'], false, 'личный кабинет'],
    [['frontendOrder', 'calculate'], false, 'AJAX-пересчёт: своего <head> у него нет'],
    [['', ''], false, 'бэкенд и CLI — параметров маршрута не существует'],
];

foreach ($route_cases as [$route, $expected, $message]) {
    assertSameValue($expected, makeDetector($route)->isCheckoutPage(), 'по маршруту: ' . $message);
}

// Мусор вместо параметров маршрута не должен ни падать, ни включать ассеты
$broken_cases = [
    [[], 'резолвер вернул пустой массив'],
    [[null, null], 'параметры маршрута не заданы'],
    [['frontend'], 'экшена в маршруте нет'],
];

foreach ($broken_cases as [$route, $message]) {
    assertSameValue(false, makeDetector($route)->isCheckoutPage(), 'мусор: ' . $message);
}

// ---------------------------------------------------------------------------
// 2. Сработавший чекаут-хук — признак сильнее маршрута
// ---------------------------------------------------------------------------

// Тема вставила форму заказа в произвольную страницу витрины: маршрут об этом молчит,
// а checkout_before_auth сработает до макета — и ассеты подключатся.
$embedded = makeDetector(['frontend', 'page']);
assertSameValue(false, $embedded->isCheckoutPage(), 'страница без формы: хуки не срабатывали');
$embedded->markCheckoutHookFired();
assertSameValue(true, $embedded->isCheckoutPage(), 'форма вставлена в произвольную страницу — хук это доказал');

// На штатном маршруте отметка ничего не ломает, а сам маршрут остаётся подстраховкой
$order_page = makeDetector(['frontend', 'order']);
$order_page->markCheckoutHookFired();
assertSameValue(true, $order_page->isCheckoutPage(), 'страница заказа: оба признака сходятся');

// Отметка неотзывная: секции чекаута рендерятся по очереди, и последняя не должна
// отменять решение, принятое на первой
$sticky = makeDetector(['frontend', 'page']);
$sticky->markCheckoutHookFired();
$sticky->markCheckoutHookFired();
assertSameValue(true, $sticky->isCheckoutPage(), 'повторная отметка не сбрасывает признак');

// ---------------------------------------------------------------------------
// 3. Маршрут читается лениво и только когда без него не обойтись
// ---------------------------------------------------------------------------

// На момент создания детектора роутинг мог ещё не отработать: резолвер обязан
// вызываться в isCheckoutPage(), а не в конструкторе
$calls = 0;
$lazy  = new shopPrefillPluginCheckoutPageDetector(static function () use (&$calls) {
    $calls++;
    return ['frontend', 'product'];
});
assertSameValue(0, $calls, 'конструктор не трогает маршрут');
$lazy->isCheckoutPage();
assertSameValue(1, $calls, 'маршрут читается при первом вопросе');

// Хук уже всё доказал — маршрут не нужен вовсе
$calls_after_hook = 0;
$marked = new shopPrefillPluginCheckoutPageDetector(static function () use (&$calls_after_hook) {
    $calls_after_hook++;
    return ['frontend', 'product'];
});
$marked->markCheckoutHookFired();
assertSameValue(true, $marked->isCheckoutPage(), 'сработавший хук решает без маршрута');
assertSameValue(0, $calls_after_hook, 'маршрут не запрашивается, когда хук уже сработал');

echo "CheckoutPageDetectorTest: OK\n";
