<?php

/**
 * Запирает три строковых договора между сервером и клиентом Дзен-режима. Это строки, которые
 * живут в двух языках и связаны только совпадением букв: рассинхрон не даёт ни ошибки в логе,
 * ни исключения — кнопка или диалог просто молчат.
 *
 *   1. Кука-просьба. `ZenLatch::EXPAND_REQUEST` и литерал в `ZenModeToggle.expandGroup()`.
 *      Клиент пишет `prefill_zen_{group}=expand-request`, сервер читает ту же куку. Разойдутся —
 *      сервер увидит чужое значение, не отличит «попросили только что» от «было развёрнуто»,
 *      и вернётся F-37: блок исчезает с экрана на клик по устаревшему снимку.
 *   2. События потери выбора. PHP шлёт `prefill_{kind}_lost` (CheckoutHooks), JS слушает то же
 *      имя для каждого вида из `LostChoiceDetector::KIND_*`. Разойдутся — диалога «Нужно выбрать
 *      другую доставку / оплату» не будет, а блок раскроется без объяснений, как до P10.
 *   3. Тексты диалогов потери. JS читает `lost_{kind}_{title|text|button}` из набора сообщений,
 *      который собирает FrontendHooks из ключей локали `dialog.lost_{kind}.*`. Пустая строка
 *      в локали — диалог без текста; в клиенте на этот случай есть запасной текст только
 *      для `text`, у заголовка и кнопки его нет.
 *   5. Диалог отзыва согласия (O-05): тексты есть, не утверждают «форма уже заполнена», говорят,
 *      что отзыв выполнен. Рвётся тихо: ложный текст не даёт ни ошибки, ни провала поведения.
 *   4. Опция `zen.lost_choice_notice` (по умолчанию выключена): ключ в схеме, переключатель в
 *      админском шаблоне с тем же именем, три строки локали в обоих языках. Рвётся тихо:
 *      переключатель без ключа в схеме не сохранится, строка без перевода покажет голый ключ.
 *
 * Тест читает исходники (JS в проекте не исполняется), поэтому проверяет именно договор, а не
 * поведение. Поведение держат ZenCollapseDecisionTest, LostChoiceDetectorTest и браузерные
 * F-37, F-38, F-40…F-43.
 *
 * Минифицированный бандл не проверяется: он пересобирается на /release-pack и между правками
 * заведомо отстаёт от исходников.
 *
 * Мутации, каждая обязана дать провал:
 *   - поменять значение EXPAND_REQUEST в ZenLatch;
 *   - переименовать `expand-request` в ZenModeToggle.js;
 *   - переименовать событие `prefill_payment_lost` в клиенте или в CheckoutHooks;
 *   - опустошить msgstr у `dialog.lost_delivery.button` в любой из локалей;
 *   - убрать из FrontendHooks ключ `lost_payment_title`;
 *   - вернуть в ru_RU текст «Форма оформления заказа уже заполнена…» или назвать кнопку «Нет».
 *
 * Запуск: php tests/ZenClientServerContractTest.php
 */

$plugin = dirname(__DIR__);

require_once $plugin . '/lib/classes/checkout/shopPrefillPluginLostChoiceDetector.class.php';

// ZenLatch тянет за собой класс логов и waRequest/waResponse в сигнатурах конструктора, но
// константы читаются без экземпляра — подгружать окружение незачем, достаточно исходника.
$latch_source = file_get_contents($plugin . '/lib/classes/zenmode/shopPrefillPluginZenLatch.class.php');
$client       = file_get_contents($plugin . '/js/modules/ZenModeToggle.js');
$hooks        = file_get_contents($plugin . '/lib/classes/hooks/shopPrefillPluginCheckoutHooks.class.php');
$frontend     = file_get_contents($plugin . '/lib/classes/hooks/shopPrefillPluginFrontendHooks.class.php');

$failures = 0;
$checks   = 0;

function check(bool $ok, string $message): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "OK: {$message}\n";
        return;
    }
    $failures++;
    echo "FAIL: {$message}\n";
}

// --- 1. Кука-просьба -----------------------------------------------------------------------

check(
    preg_match("/public const EXPAND_REQUEST = '([^']+)';/", $latch_source, $m) === 1,
    'ZenLatch объявляет публичную константу EXPAND_REQUEST'
);
$request_value = $m[1] ?? '';
check($request_value !== '', 'значение просьбы не пустое');

preg_match("/public const COOKIE_PREFIX = '([^']+)';/", $latch_source, $m);
$cookie_prefix = $m[1] ?? '';
check($cookie_prefix !== '', 'ZenLatch объявляет префикс куки группы');

// Клиент пишет куку просьбы ровно с этим значением и этим префиксом
check(
    strpos($client, 'var cookieName = "' . $cookie_prefix . '" + group;') !== false,
    "клиент строит имя куки от того же префикса '{$cookie_prefix}'"
);
check(
    strpos($client, 'cookieName + "=' . $request_value . ';') !== false,
    "клиент пишет просьбу значением '{$request_value}' — тем, что читает сервер"
);

// Просьба не должна совпасть с состоянием: иначе опечатка превратит одно в другое
check(
    preg_match("/private const EXPANDED = '([^']+)';/", $latch_source, $m) === 1 && $m[1] !== $request_value,
    'значение просьбы отличается от значения защёлки'
);

// Клиент не пишет состояние «развёрнута» сам: это решает только сервер (sync)
check(
    strpos($client, 'cookieName + "=expanded') === false,
    'клиент не ставит защёлку «expanded» — её пишет только сервер'
);

// --- 2. События потери выбора --------------------------------------------------------------

check(
    strpos($hooks, '"prefill_\' . $dialog . \'_lost"') !== false,
    'CheckoutHooks шлёт событие по шаблону prefill_{вид}_lost'
);

$kinds = [
    shopPrefillPluginLostChoiceDetector::KIND_DELIVERY,
    shopPrefillPluginLostChoiceDetector::KIND_PAYMENT,
];

foreach ($kinds as $kind) {
    check(
        strpos($client, '$(document).on("prefill_' . $kind . '_lost"') !== false,
        "клиент слушает prefill_{$kind}_lost"
    );
}

// Каждый вид, который умеет вернуть pickDialog(), обязан иметь обработчик
$facts_lost = ['lost' => true, 'reason' => 'missing_in_response'];
$facts_none = ['lost' => false, 'reason' => 'kept'];
$picked     = [
    shopPrefillPluginLostChoiceDetector::pickDialog($facts_lost, $facts_none),
    shopPrefillPluginLostChoiceDetector::pickDialog($facts_none, $facts_lost),
];
foreach ($picked as $kind) {
    check(in_array($kind, $kinds, true), "pickDialog() отдаёт только известные виды ('{$kind}')");
}

// --- 3. Тексты диалогов --------------------------------------------------------------------

/** msgid → msgstr из .po; многострочные значения тест не поддерживает и не ждёт. */
function readPo(string $path): array
{
    $map = [];
    if (!is_file($path)) {
        return $map;
    }
    preg_match_all('/^msgid "([^"]+)"\nmsgstr "(.*)"$/m', file_get_contents($path), $found, PREG_SET_ORDER);
    foreach ($found as $row) {
        $map[$row[1]] = stripcslashes($row[2]);
    }
    return $map;
}

$po = [
    'ru_RU' => readPo($plugin . '/locale/ru_RU/LC_MESSAGES/shop_prefill.po'),
    'en_US' => readPo($plugin . '/locale/en_US/LC_MESSAGES/shop_prefill.po'),
];

foreach ($kinds as $kind) {
    foreach (['title', 'text', 'button'] as $part) {
        $js_key     = "lost_{$kind}_{$part}";
        $locale_key = "dialog.lost_{$kind}.{$part}";

        check(
            strpos($frontend, "'{$js_key}'") !== false && strpos($frontend, "_wp('{$locale_key}')") !== false,
            "FrontendHooks передаёт клиенту {$js_key} из {$locale_key}"
        );

        foreach ($po as $locale => $map) {
            check(
                isset($map[$locale_key]) && trim($map[$locale_key]) !== '',
                "{$locale}: строка {$locale_key} есть и не пустая"
            );
        }
    }
}

// Клиент собирает имя ключа из вида — шаблон должен совпадать с тем, что передаёт сервер
check(
    strpos($client, 'this.messages[`lost_${kind}_title`]') !== false
    && strpos($client, 'this.messages[`lost_${kind}_text`]') !== false
    && strpos($client, 'this.messages[`lost_${kind}_button`]') !== false,
    'клиент читает lost_{вид}_{title|text|button} — те же имена, что у сервера'
);

// --- 4. Опция предупреждений: схема ↔ админский шаблон ↔ локаль ----------------------------

$schema = include $plugin . '/lib/config/storefront.settings.php';
check(
    isset($schema['zen']['lost_choice_notice']) && $schema['zen']['lost_choice_notice']['value'] === false,
    'опция zen.lost_choice_notice есть в схеме и выключена по умолчанию'
);
check(
    ($schema['zen']['lost_choice_notice']['filter'] ?? null) === FILTER_VALIDATE_BOOLEAN,
    'у опции фильтр FILTER_VALIDATE_BOOLEAN (иначе выключенный переключатель не сохранится)'
);

$zen_tab = file_get_contents($plugin . '/templates/actions/settings/blocks/tabs/Zen.html');
check(
    strpos($zen_tab, '{$name_prefix}[zen][lost_choice_notice]') !== false
    && strpos($zen_tab, "value=\$settings['zen']['lost_choice_notice']") !== false,
    'вкладка «Дзен-режим» несёт переключатель с именем [zen][lost_choice_notice] и значением из настроек'
);

foreach (['zen.setting.lost_choice_notice', 'zen.tooltip.lost_choice_notice', 'hint.zen.lost_choice_notice'] as $key) {
    check(strpos($zen_tab, '[`' . $key . '`]') !== false, "шаблон использует строку {$key}");
    foreach ($po as $locale => $map) {
        check(isset($map[$key]) && trim($map[$key]) !== '', "{$locale}: строка {$key} есть и не пустая");
    }
}

// Клиент опцию не знает: диалоги приходят событием, когда сервер сам решил их показать
check(strpos($client, 'lost_choice_notice') === false, 'клиент не читает опцию: решение целиком на сервере (выключено — события нет)');

// --- 5. Диалог отзыва согласия: тексты и правдивость (O-05) -------------------------------

// Клиент уже отправил `revoke` к моменту показа диалога, а заполненность формы код не проверяет.
// Поэтому текст не вправе утверждать, что форма «уже заполнена» (ложь на пустой форме), и обязан
// сказать, что отзыв выполнен — иначе «Нет» читается как отмена отзыва.
foreach (['title', 'text', 'confirm', 'cancel'] as $part) {
    check(
        strpos($frontend, "'consent_revoke_{$part}'") !== false && strpos($frontend, "_wp('dialog.consent_revoke.{$part}')") !== false,
        "FrontendHooks передаёт клиенту consent_revoke_{$part}"
    );
    foreach ($po as $locale => $map) {
        check(
            isset($map["dialog.consent_revoke.{$part}"]) && trim($map["dialog.consent_revoke.{$part}"]) !== '',
            "{$locale}: строка dialog.consent_revoke.{$part} есть и не пустая"
        );
    }
}
check(
    preg_match('/уже заполнен/iu', $po['ru_RU']['dialog.consent_revoke.text'] ?? '') === 0
    && preg_match('/already filled/i', $po['en_US']['dialog.consent_revoke.text'] ?? '') === 0,
    'текст диалога отзыва не утверждает, что форма «уже заполнена» (код этого не проверяет)'
);
check(
    preg_match('/удален/iu', $po['ru_RU']['dialog.consent_revoke.text'] ?? '') === 1
    && preg_match('/deleted/i', $po['en_US']['dialog.consent_revoke.text'] ?? '') === 1,
    'текст диалога отзыва говорит, что сохранённые данные уже удалены'
);
check(
    ($po['ru_RU']['dialog.consent_revoke.cancel'] ?? '') !== 'Нет' && ($po['en_US']['dialog.consent_revoke.cancel'] ?? '') !== 'No',
    'кнопка отказа не называется «Нет»/«No»: она не отменяет отзыв, а оставляет введённое в форму'
);

echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
