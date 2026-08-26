<?php

/**
 * Проверяет таблицу решений гео-синхронизации: подставлять город стороннему плагину,
 * отступить или ничего не делать.
 *
 * Правила, которые здесь защищаются:
 *   G1  — пишем только в вакуум либо поверх собственной прошлой записи;
 *   G1a — первая встреча (город есть, нашей записи нет) разрешает однократную перезапись:
 *         отличить ручной выбор от старого определения по IP невозможно;
 *   P1/P2 — значение, которое покупатель сменил сам, неприкосновенно.
 *
 * Сравнение идёт по хранилищу стороннего плагина, а не по order.region: в секцию region
 * пишет ещё и ядро (дефолтная страна и город из настроек локации, см. P2), поэтому
 * расхождение там ничего не доказывает.
 *
 * Запуск: php tests/GeoSyncDecisionTest.php
 */

// Логгер требует поднятого Webasyst; в этом классе он только пишет debug
if (!class_exists('shopPrefillPluginLog')) {
    class shopPrefillPluginLog
    {
        public static function debug($message, $context = null): void {}
        public static function info($message, $context = null): void {}
        public static function warning($message, $context = null): void {}
        public static function error($message, $context = null): void {}
    }
}

require_once dirname(__DIR__) . '/lib/classes/geosync/shopPrefillPluginGeoTarget.class.php';
require_once dirname(__DIR__) . '/lib/classes/geosync/shopPrefillPluginGeoSync.class.php';

$failures = 0;
$checks   = 0;

function check($expected, $actual, string $message): void
{
    global $failures, $checks;
    $checks++;
    if ($expected !== $actual) {
        $failures++;
        echo "  FAIL  {$message}: expected " . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL;
    }
}

function city(string $name, string $region = '16', string $country = 'rus', string $zip = ''): shopPrefillPluginGeoTarget
{
    return new shopPrefillPluginGeoTarget($country, $region, $name, $zip);
}

function nothing(): shopPrefillPluginGeoTarget
{
    return new shopPrefillPluginGeoTarget(null, null, null, null);
}

$APPLY   = shopPrefillPluginGeoSync::DECISION_APPLY;
$BACKOFF = shopPrefillPluginGeoSync::DECISION_BACKOFF;
$SKIP    = shopPrefillPluginGeoSync::DECISION_SKIP;

$kazan  = city('Казань', '16');
$moscow = city('Москва', '77');

echo '--- Нечего подставлять ---' . PHP_EOL;

check($SKIP, shopPrefillPluginGeoSync::decide(nothing(), null, nothing()),
    'города в истории нет — не трогаем ничего');
check($SKIP, shopPrefillPluginGeoSync::decide($moscow, $moscow, nothing()),
    'города в истории нет, у плагина свой — не трогаем');

echo '--- G1: вакуум занимаем ---' . PHP_EOL;

check($APPLY, shopPrefillPluginGeoSync::decide(nothing(), null, $kazan),
    'у плагина пусто, мы не писали — занимаем вакуум');
check($APPLY, shopPrefillPluginGeoSync::decide(nothing(), $moscow, $kazan),
    'у плагина пусто, хотя мы писали (куки почистили) — занимаем снова');

echo '--- G1a: первая встреча перезаписывает однократно ---' . PHP_EOL;

check($APPLY, shopPrefillPluginGeoSync::decide($moscow, null, $kazan),
    'город есть, нашей записи нет — первая встреча, перезаписываем');
check($APPLY, shopPrefillPluginGeoSync::decide($kazan, null, $kazan),
    'город есть и совпадает, но записи нет — всё равно фиксируем авторство');

echo '--- G1/P1: чужой выбор неприкосновенен ---' . PHP_EOL;

check($BACKOFF, shopPrefillPluginGeoSync::decide($moscow, $kazan, $kazan),
    'мы писали Казань, сейчас Москва — сменил покупатель, отступаем');
check($BACKOFF, shopPrefillPluginGeoSync::decide($moscow, $kazan, $moscow),
    'отступаем даже когда его выбор совпал с историей: авторство не наше');

echo '--- Наша запись ---' . PHP_EOL;

check($SKIP, shopPrefillPluginGeoSync::decide($kazan, $kazan, $kazan),
    'наша запись и она же актуальна — делать нечего');
check($APPLY, shopPrefillPluginGeoSync::decide($kazan, $kazan, $moscow),
    'наша запись, но покупатель заказал в другой город — обновляем');

echo '--- Подпись состояния ---' . PHP_EOL;

check(true, city('Казань', '16', 'rus', '420000')->equals(city('Казань', '16', 'rus', '')),
    'индекс в подпись не входит: сторонние плагины теряют и восстанавливают его по-своему');
check(true, city('казань')->equals(city('КАЗАНЬ')),
    'регистр названия города не считается расхождением');
check(false, city('Казань', '16')->equals(city('Казань', '77')),
    'тёзки городов в разных регионах — разные города');
check(true, nothing()->isEmpty(), 'без города объект пуст');
check(false, city('Казань')->isEmpty(), 'город есть — объект не пуст');

echo '--- Перенос состояния через массив (кука и сессия) ---' . PHP_EOL;

$restored = shopPrefillPluginGeoTarget::fromArray($kazan->toArray());
check(true, $restored->equals($kazan), 'слепок переживает сериализацию в массив и обратно');
check(true, shopPrefillPluginGeoTarget::fromArray(null)->isEmpty(), 'мусор в куке даёт пустой слепок');
check(true, shopPrefillPluginGeoTarget::fromArray(['city' => '   '])->isEmpty(),
    'пробелы вместо города не считаются городом');

echo PHP_EOL;
if ($failures === 0) {
    echo "OK: {$checks} проверок пройдено" . PHP_EOL;
    exit(0);
}
echo "ПРОВАЛЕНО: {$failures} из {$checks}" . PHP_EOL;
exit(1);
