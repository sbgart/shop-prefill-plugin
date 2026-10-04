<?php

/**
 * Запирает цепочку точных поводов диалога: опознаватель ядра → ключ плагина → строка локали → клиент.
 *
 * Зачем. Общий текст диалога верен всегда, но для самого частого случая — «способ оплаты не
 * выбран» — он звучал как обвинение: «не все поля заполнены верно», хотя покупатель ничего
 * неверного не вводил (замечание владельца 04.10.2026). Точный текст берётся по опознавателю
 * ошибки ядра, а не по её тексту: тексты ядра расходятся между клиентом и сервером, а у
 * плагинов доставки бывают и вовсе чужие — пересказывать их мы отказались.
 *
 * Цепочка рвётся тихо в трёх местах, поэтому каждое заперто:
 *   1. ядро переименовало опознаватель при обновлении — точный текст молча перестанет появляться;
 *   2. плагин перестал передавать ключ в JS — то же самое;
 *   3. в локали пусто — диалог покажет пустоту вместо текста.
 *
 * Мутации, каждая обязана дать провал:
 *   - переименовать опознаватель в FrontendHooks;
 *   - опустошить строку локали;
 *   - убрать resolveReasonMessage() из клиента.
 *
 * Запуск: php tests/ZenValidationReasonsTest.php
 */

$plugin = dirname(__DIR__);
$core_form_js = $plugin . '/../../js/frontend/order/form.js';

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

/** Опознаватели, на которые мы рассчитываем. Их список — договор с ядром. */
const REASON_IDS = ['method_required', 'variant_required', 'type_required'];

$hooks  = file_get_contents($plugin . '/lib/classes/hooks/shopPrefillPluginFrontendHooks.class.php');
$client = file_get_contents($plugin . '/js/modules/ZenModeToggle.js');

// --- 1. Ядро действительно отдаёт эти опознаватели ---

if (is_file($core_form_js)) {
    $core = file_get_contents($core_form_js);
    foreach (REASON_IDS as $id) {
        check(strpos($core, 'id: "' . $id . '"') !== false, "ядро отдаёт опознаватель '{$id}'");
    }
} else {
    echo "SKIP: js/frontend/order/form.js не найден — проверка договора с ядром пропущена\n";
}

// --- 2. Плагин передаёт ровно эти ключи клиенту ---

check(strpos($hooks, "'validation_reasons'") !== false, 'плагин передаёт набор точных поводов в JS');

foreach (REASON_IDS as $id) {
    $pattern = "/'" . preg_quote($id, '/') . "'\s*=>\s*_wp\('zen\.validation\.reason\." . preg_quote($id, '/') . "'\)/";
    check(preg_match($pattern, $hooks) === 1, "ключ '{$id}' ведёт в строку локали zen.validation.reason.{$id}");
}

// --- 3. В обеих локалях строки есть и они не пустые ---

foreach (['ru_RU', 'en_US'] as $locale) {
    $po = file_get_contents($plugin . "/locale/{$locale}/LC_MESSAGES/shop_prefill.po");
    $mo = file_get_contents($plugin . "/locale/{$locale}/LC_MESSAGES/shop_prefill.mo");

    foreach (REASON_IDS as $id) {
        $key = "zen.validation.reason.{$id}";
        preg_match('/msgid "' . preg_quote($key, '/') . '"\s*\nmsgstr "([^"]*)"/u', $po, $m);
        check(!empty($m[1]), "{$locale}: строка {$key} есть и не пуста");
        if (!empty($m[1])) {
            check(strpos($mo, $m[1]) !== false, "{$locale}: строка {$key} скомпилирована в .mo");
        }
    }
}

// --- 4. Клиент выбирает текст по опознавателю, а не по тексту ошибки ---

check(strpos($client, 'resolveReasonMessage') !== false, 'клиент выбирает текст по опознавателю');
check(strpos($client, 'validation_reasons') !== false, 'клиент читает набор поводов, переданный сервером');
check(preg_match('/reasons\.length !== 1/', $client) === 1, 'точный текст только когда повод ровно один');

// Опознаватели в клиенте не продублированы: он знает их только из набора сервера
$hardcoded = 0;
foreach (REASON_IDS as $id) {
    if (strpos($client, '"' . $id . '"') !== false || strpos($client, "'" . $id . "'") !== false) {
        $hardcoded++;
    }
}
check($hardcoded === 0, 'клиент не дублирует список опознавателей у себя');

echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
