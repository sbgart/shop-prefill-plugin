<?php

/**
 * A6 (TEST-PLAN.md §5), правило B3: shopPrefillPluginStorefrontCollection — getByCode(),
 * getTree(), toJson() с глобальной витриной.
 *
 * add() молча отбрасывает повторную запись того же кода — это коллекционный аналог
 * реального инцидента с дублирующимся checkout_storefront_id (см. CLAUDE.md, «Несколько
 * витрин на стенде»: поддомен `shop-1.wa-dev.loc` делил id с `wa-dev.loc/shop-1/*` до
 * 07.09.2026 — интерфейс показывал две карточки, побеждала последняя сохранённая).
 *
 * shopPrefillPluginStorefront::isActive() читает БД через setting_provider — провайдер
 * подменён наследником настоящего shopPrefillPluginStorefrontSettingProvider с пустым
 * конструктором (тот же приём, что FakeSourceKeyProvider в ZenSummaryCacheIdentityTest):
 * наследование держит тест на реальной сигнатуре getSettings(), а пропуск parent::__construct()
 * избавляет от shopPrefillPluginSettingsModel/Config и живой БД.
 *
 * Запуск: php tests/StorefrontCollectionTest.php
 */

require_once dirname(__DIR__) . '/lib/classes/storefronts/shopPrefillPluginStorefrontCode.class.php';
require_once dirname(__DIR__) . '/lib/classes/settings/providers/shopPrefillPluginAbstractSettingProvider.class.php';
require_once dirname(__DIR__) . '/lib/classes/settings/providers/shopPrefillPluginStorefrontSettingProvider.class.php';
require_once dirname(__DIR__) . '/lib/classes/storefronts/shopPrefillPluginStorefront.class.php';
require_once dirname(__DIR__) . '/lib/classes/storefronts/shopPrefillPluginStorefrontCollection.class.php';

/** Наследник настоящего провайдера: та же сигнатура getSettings(), без БД. */
class FakeStorefrontSettingProvider extends shopPrefillPluginStorefrontSettingProvider
{
    /** @var array<string, bool> код витрины => active */
    private array $active_by_code;

    public function __construct(array $active_by_code = [])
    {
        $this->active_by_code = $active_by_code;
    }

    public function getSettings(string $storefront_code): array
    {
        return ['active' => $this->active_by_code[$storefront_code] ?? false];
    }
}

function storefront(string $domain, string $url, array $route, array $active_by_code = []): shopPrefillPluginStorefront
{
    return new shopPrefillPluginStorefront($domain, $url, new FakeStorefrontSettingProvider($active_by_code), $route);
}

// --- Инфраструктура проверок ------------------------------------------------

$failures = 0;
$checks   = 0;

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function check($expected, $actual, string $message): void
{
    global $failures, $checks;
    $checks++;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$message}\n";
        echo '  expected: ' . var_export($expected, true) . "\n";
        echo '  actual:   ' . var_export($actual, true) . "\n";
    }
}

// ---------------------------------------------------------------------------
// has() / add() / getByCode()
// ---------------------------------------------------------------------------

$collection = new shopPrefillPluginStorefrontCollection();
check(false, $collection->has('anything'), 'пустая коллекция ничего не содержит');
check(null, $collection->getByCode('anything'), 'getByCode() на пустой коллекции — null');

$main = storefront('wa-dev.loc', '*', ['checkout_storefront_id' => 'id-main']);
$collection->add($main);

check(true, $collection->has('id-main'), 'после add() код найден через has()');
check($main, $collection->getByCode('id-main'), 'getByCode() возвращает тот же объект, что был добавлен');

// ---------------------------------------------------------------------------
// add(): повторный код — первая запись побеждает (issue дублирующегося checkout_storefront_id)
// ---------------------------------------------------------------------------

$duplicate = storefront('shop-1.wa-dev.loc', '*', ['checkout_storefront_id' => 'id-main']); // тот же id, другой домен
$collection->add($duplicate);

check(
    $main,
    $collection->getByCode('id-main'),
    'add() с уже занятым кодом не подменяет запись — первая добавленная витрина остаётся в коллекции'
);
check(1, count($collection->getList()), 'дубликат по коду не увеличивает размер коллекции');

// ---------------------------------------------------------------------------
// getTree(): группировка domain => url => Storefront
// ---------------------------------------------------------------------------

$collection = new shopPrefillPluginStorefrontCollection();
$sf1        = storefront('wa-dev.loc', '*', ['checkout_storefront_id' => 'id-1']);
$sf2        = storefront('wa-dev.loc', 'shop-1/*', ['checkout_storefront_id' => 'id-2']);
$sf3        = storefront('shop-1.wa-dev.loc', '*', ['checkout_storefront_id' => 'id-3']);

$collection->add($sf1);
$collection->add($sf2);
$collection->add($sf3);

$tree = $collection->getTree();

check(2, count($tree), 'два разных домена — два верхних узла дерева');
check(2, count($tree['wa-dev.loc']), 'у wa-dev.loc два раздела (* и shop-1/*)');
check($sf1, $tree['wa-dev.loc']['*'], 'узел дерева — тот же объект витрины (главная)');
check($sf2, $tree['wa-dev.loc']['shop-1/*'], 'узел дерева — тот же объект витрины (второй раздел)');
check(1, count($tree['shop-1.wa-dev.loc']), 'у отдельного поддомена один раздел');
check($sf3, $tree['shop-1.wa-dev.loc']['*'], 'узел дерева поддомена — тот же объект витрины');

// ---------------------------------------------------------------------------
// toJson(): статусы и группировка по домену
// ---------------------------------------------------------------------------

$active_map = ['id-1' => true, 'id-2' => false, 'id-3' => true];
$collection = new shopPrefillPluginStorefrontCollection();
$collection->add(storefront('wa-dev.loc', '*', ['checkout_storefront_id' => 'id-1'], $active_map));
$collection->add(storefront('wa-dev.loc', 'shop-1/*', ['checkout_storefront_id' => 'id-2'], $active_map));

$global = new shopPrefillPluginStorefront('*', '*', new FakeStorefrontSettingProvider(['*' => true]));

$decoded = json_decode($collection->toJson($global), true);

check(true, is_array($decoded), 'toJson() отдаёт валидный JSON');
check(1, count($decoded['storefronts']), 'один домен в сгруппированном списке (оба раздела на wa-dev.loc)');
check('wa-dev.loc', $decoded['storefronts'][0]['domain'], 'domain сгруппированного узла верный');
check(2, count($decoded['storefronts'][0]['items']), 'у домена два раздела в items');

check(true, $decoded['statuses']['id-1'], 'статус активной витрины — true');
check(false, $decoded['statuses']['id-2'], 'статус неактивной витрины — false');
check(true, $decoded['statuses']['*'], 'статус глобальной витрины берётся из переданного $global_storefront');

// Без $global_storefront — фоллбэк true для обратной совместимости (как в реальном коде)
$decoded_no_global = json_decode($collection->toJson(), true);
check(true, $decoded_no_global['statuses']['*'], 'без глобальной витрины statuses[*] по умолчанию true (обратная совместимость)');

// ---------------------------------------------------------------------------
echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
