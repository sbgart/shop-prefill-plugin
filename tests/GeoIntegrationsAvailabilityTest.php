<?php

/**
 * Проверяет, что тумблер интеграции ничего не значит без установленного плагина.
 *
 * Зачем: администратор может включить интеграцию «на будущее» или удалить сторонний плагин
 * уже после включения — в настройках витрины останется `true`, будто интеграция работает.
 * Единственный источник правды — доступность самого плагина, и она обязана перекрывать
 * сохранённое значение и на чтении настроек, и на записи, и в адаптерах.
 *
 * Запуск: php tests/GeoIntegrationsAvailabilityTest.php
 */

// Реестр спрашивает у плагина только один вопрос — установлен ли сосед. Подменяем ответ.
class shopPrefillPlugin
{
    /** @var array<string, bool> */
    public static array $installed = [];

    public static function enableInstall($plugin_id): bool
    {
        return ! empty(self::$installed[$plugin_id]);
    }
}

require_once dirname(__DIR__) . '/lib/classes/geosync/shopPrefillPluginGeoIntegrations.class.php';

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

/**
 * @param array<string, bool> $installed
 */
function installed(array $installed): void
{
    shopPrefillPlugin::$installed = $installed;
    shopPrefillPluginGeoIntegrations::clearCache();
}

/**
 * @param array<string, bool> $integration
 * @return array<string, mixed>
 */
function settings(array $integration): array
{
    return ['prefill' => ['integration' => $integration]];
}

$all_on = ['cityselect' => true, 'regions' => true, 'dp' => true];

echo '--- Тумблер без плагина не работает ---' . PHP_EOL;

installed([]);
check(false, shopPrefillPluginGeoIntegrations::isEnabled(settings($all_on), 'cityselect'),
    'плагин не установлен — интеграция выключена, что бы ни лежало в настройках');
check(false, shopPrefillPluginGeoIntegrations::isEnabled(settings($all_on), 'regions'),
    'то же для regions');
check(false, shopPrefillPluginGeoIntegrations::isEnabled(settings($all_on), 'dp'),
    'то же для dp');
check(false, shopPrefillPluginGeoIntegrations::hasEnabled(settings($all_on)),
    'ни одной работоспособной интеграции');

echo '--- Плагин есть: решает тумблер ---' . PHP_EOL;

installed(['cityselect' => true]);
check(true, shopPrefillPluginGeoIntegrations::isEnabled(settings($all_on), 'cityselect'),
    'плагин установлен и тумблер включён');
check(false, shopPrefillPluginGeoIntegrations::isEnabled(settings(['cityselect' => false]), 'cityselect'),
    'плагин установлен, тумблер выключен');
check(false, shopPrefillPluginGeoIntegrations::isEnabled(settings([]), 'cityselect'),
    'настройки без узла интеграции читаются как выключено');
check(true, shopPrefillPluginGeoIntegrations::hasEnabled(settings($all_on)),
    'одной работоспособной достаточно');

echo '--- Дешёвый гард: есть ли вообще плагин выбора города ---' . PHP_EOL;

installed(['dp' => true]);
check(false, shopPrefillPluginGeoIntegrations::hasAnyCityProvider(),
    'один dp города не даёт — гео-синхронизацию не поднимаем');
installed(['regions' => true]);
check(true, shopPrefillPluginGeoIntegrations::hasAnyCityProvider(),
    'regions установлен — поднимаем');

echo '--- Гашение сохранённого значения ---' . PHP_EOL;

installed(['cityselect' => true]);
$sanitized = shopPrefillPluginGeoIntegrations::sanitize(settings($all_on));
check(true, $sanitized['prefill']['integration']['cityselect'],
    'установленный плагин настройку не трогает');
check(false, $sanitized['prefill']['integration']['regions'],
    'удалённый плагин гасит своё `true`');
check(false, $sanitized['prefill']['integration']['dp'],
    'то же для dp');

installed([]);
check(['zen' => ['active' => true]], shopPrefillPluginGeoIntegrations::sanitize(['zen' => ['active' => true]]),
    'частичное сохранение без ветки prefill не трогаем — иначе в базу поедут лишние строки');

$empty_prefill = shopPrefillPluginGeoIntegrations::sanitize(['prefill' => ['sections' => []]]);
check(false, $empty_prefill['prefill']['integration']['cityselect'],
    'ветка prefill есть, узла интеграции нет — узел создаётся выключенным');

echo PHP_EOL;
if ($failures === 0) {
    echo "OK: {$checks} проверок пройдено" . PHP_EOL;
    exit(0);
}
echo "ПРОВАЛЕНО: {$failures} из {$checks}" . PHP_EOL;
exit(1);
