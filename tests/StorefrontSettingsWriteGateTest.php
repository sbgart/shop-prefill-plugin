<?php

/**
 * Два новых узла записи настроек витрины (issue-96):
 * - filterKnown() отбрасывает ключи, которых нет в схеме, и не подставляет дефолты за
 *   отсутствующие ветки — иначе частичное сохранение стирало бы остальное дерево до дефолтов
 * - stripTemplateWritesForNonAdmin() режет zen.groups.*.summary_template/custom_templates
 *   для не-администратора (Smarty без enableSecurity() — исполнение произвольного PHP,
 *   RULES.md B4), но не трогает остальные поля той же формы, которые правом `settings`
 *   писать можно
 *
 * Запуск: php tests/StorefrontSettingsWriteGateTest.php
 */

$GLOBALS['__is_admin'] = false;

function wa()
{
    return new class {
        public function getUser()
        {
            return new class {
                public function isAdmin($app_id)
                {
                    return $GLOBALS['__is_admin'];
                }
            };
        }
    };
}

class shopPrefillPlugin
{
    public const APP_ID = 'shop';
}

require_once dirname(__DIR__) . '/lib/classes/settings/shopPrefillPluginSettingField.class.php';
require_once dirname(__DIR__) . '/lib/classes/settings/shopPrefillPluginSettingGroup.class.php';
require_once dirname(__DIR__) . '/lib/classes/settings/providers/shopPrefillPluginAbstractSettingProvider.class.php';
require_once dirname(__DIR__) . '/lib/classes/settings/providers/shopPrefillPluginStorefrontSettingProvider.class.php';

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

// --- filterKnown(): вайтлист известных схеме ключей ---

echo '--- filterKnown() ---' . PHP_EOL;

$schema = new shopPrefillPluginSettingGroup([
    'active' => new shopPrefillPluginSettingField(false, FILTER_VALIDATE_BOOLEAN),
    'zen'    => new shopPrefillPluginSettingGroup([
        'groups' => new shopPrefillPluginSettingGroup([
            'payment' => new shopPrefillPluginSettingGroup([
                'summary_template' => new shopPrefillPluginSettingField(''),
                'custom_templates' => new shopPrefillPluginSettingField([]),
            ]),
        ]),
    ]),
]);

$filtered = $schema->filterKnown([
    'active'  => 'true',
    'unknown' => 'shell_exec payload', // не из схемы
    'zen'     => [
        'groups' => [
            'payment' => [
                'summary_template' => '{$x|shell_exec}',
                'evil_leaf'        => 'x', // не из схемы, соседний с легитимным листом
            ],
        ],
    ],
]);

check(true, ! array_key_exists('unknown', $filtered), 'неизвестный top-level ключ отброшен');
check(true, ! array_key_exists('evil_leaf', $filtered['zen']['groups']['payment']), 'неизвестный вложенный ключ отброшен');
check('{$x|shell_exec}', $filtered['zen']['groups']['payment']['summary_template'], 'известный лист прошёл как есть (у него нет фильтра)');
check(true, $filtered['active'], 'FILTER_VALIDATE_BOOLEAN применяется уже на записи, не только на чтении');

$partial = $schema->filterKnown(['zen' => ['groups' => ['payment' => ['summary_template' => 'x']]]]);
check(false, array_key_exists('active', $partial), 'частичное сохранение не подставляет дефолт за отсутствующую ветку');

$not_array = $schema->filterKnown(['zen' => 'not-an-array']);
check(false, array_key_exists('zen', $not_array), 'группа, присланная не массивом, отбрасывается целиком');

// --- stripTemplateWritesForNonAdmin(): гейт issue-96 ---

echo '--- stripTemplateWritesForNonAdmin() ---' . PHP_EOL;

$provider = (new ReflectionClass(shopPrefillPluginStorefrontSettingProvider::class))->newInstanceWithoutConstructor();
$method   = new ReflectionMethod($provider, 'stripTemplateWritesForNonAdmin');
$method->setAccessible(true);

$payload = [
    'active' => true,
    'zen'    => [
        'groups' => [
            'customer' => ['summary_template' => '{$x|shell_exec}'],
            'delivery' => ['summary_template' => 'a', 'custom_templates' => ['1' => 'b']],
            'payment'  => ['summary_template' => 'c', 'custom_templates' => ['2' => 'd']],
        ],
    ],
];

$GLOBALS['__is_admin'] = false;
$stripped = $method->invoke($provider, $payload);

check(true, ! isset($stripped['zen']['groups']['customer']['summary_template']), 'не-админ: customer.summary_template вырезан');
check(true, ! isset($stripped['zen']['groups']['delivery']['summary_template']), 'не-админ: delivery.summary_template вырезан');
check(true, ! isset($stripped['zen']['groups']['delivery']['custom_templates']), 'не-админ: delivery.custom_templates вырезан');
check(true, ! isset($stripped['zen']['groups']['payment']['summary_template']), 'не-админ: payment.summary_template вырезан');
check(true, ! isset($stripped['zen']['groups']['payment']['custom_templates']), 'не-админ: payment.custom_templates вырезан');
check(true, $stripped['active'], 'не-админ: остальные поля формы (не шаблоны) проходят как есть — их запрет сломал бы право settings');

$GLOBALS['__is_admin'] = true;
$kept = $method->invoke($provider, $payload);
check($payload, $kept, 'админ: дерево не тронуто');

echo PHP_EOL;
if ($failures) {
    echo "FAILED: {$failures} / {$checks} checks" . PHP_EOL;
    exit(1);
}
echo "OK: {$checks} checks passed" . PHP_EOL;
