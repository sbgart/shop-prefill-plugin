<?php

/**
 * Замок на правила, по которым пишутся тексты диалогов витрины (ФО-15 в docs/tests/TEST-PLAN.md).
 *
 * Правила выведены из замечаний владельца плагина 19.09.2026, когда диалог блокировки говорил
 * покупателю «В разделе «Оплата» есть ошибка», а ошибки на экране не было:
 *
 *  1. Никакого слова «блок» («block»): покупатель не знает, что это. Он видит заголовки
 *     «Покупатель», «Доставка», «Оплата», а «блок» — внутренний термин разработки.
 *  2. Никаких названий цветов («красным», «red»): цвет ошибки задаёт тема оформления, и текст,
 *     обещающий красное, врёт на любой другой теме.
 *
 * Проверяется только витринная поверхность — то, что видит покупатель. Админские подсказки
 * («Разрешает предзаполнять блок «Подтверждение»») читает администратор, для него «блок» —
 * термин Shop-Script, и правило на них не распространяется.
 *
 * Тест обязан уметь падать: он принимает необязательный аргумент — каталог locale/, чтобы
 * мутационная проверка могла подсунуть испорченную копию (см. TESTS.md, «Тест обязан уметь падать»).
 *
 * Запуск: php tests/DialogTextsPolicyTest.php [каталог-locale]
 */

$locale_root = $argv[1] ?? dirname(__DIR__) . '/locale';

/** Ключи витринных диалогов: точное имя либо префикс (оканчивается на точку) */
const STOREFRONT_DIALOG_KEYS = [
    'zen.validation.',
    'zen.nothing_to_summarize.',
    'dialog.delivery_unavailable.',
    'dialog.params_choice.',
    'dialog.consent_revoke.',
    'dialog.content.',
    'dialog.header.choose_delivery',
];

/** Запрещённые слова по локалям: [шаблон, почему] */
const FORBIDDEN = [
    'ru_RU' => [
        ['/блок/iu', 'слово «блок» — внутренний термин, покупатель его не знает'],
        ['/красн|зелён|зелен|син(ий|яя|ем|им|ее)/iu', 'название цвета: цвет ошибки задаёт тема оформления'],
    ],
    'en_US' => [
        ['/\bblocks?\b/i', 'the word "block" is an internal term the buyer does not know'],
        ['/\b(red|green|blue)\b/i', 'colour name: the theme decides how errors look'],
    ],
];

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

/**
 * Минимальный разбор .po: msgid → msgstr, с поддержкой строк-продолжений.
 *
 * @return array<string, string>
 */
function parsePo(string $path): array
{
    $map  = [];
    $id   = null;
    $mode = null;
    $buf  = ['msgid' => '', 'msgstr' => ''];

    $flush = static function () use (&$map, &$buf): void {
        if ($buf['msgid'] !== '') {
            $map[$buf['msgid']] = $buf['msgstr'];
        }
        $buf = ['msgid' => '', 'msgstr' => ''];
    };

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^msgid "(.*)"$/u', $line, $m)) {
            $flush();
            $mode = 'msgid';
            $buf['msgid'] = stripcslashes($m[1]);
        } elseif (preg_match('/^msgstr "(.*)"$/u', $line, $m)) {
            $mode = 'msgstr';
            $buf['msgstr'] = stripcslashes($m[1]);
        } elseif ($mode !== null && preg_match('/^"(.*)"$/u', $line, $m)) {
            $buf[$mode] .= stripcslashes($m[1]);
        } elseif (trim($line) === '') {
            $flush();
            $mode = null;
        }
    }
    $flush();

    return $map;
}

function isStorefrontDialogKey(string $key): bool
{
    foreach (STOREFRONT_DIALOG_KEYS as $rule) {
        $is_prefix = substr($rule, -1) === '.';
        if ($is_prefix ? strpos($key, $rule) === 0 : $key === $rule) {
            return true;
        }
    }
    return false;
}

foreach (FORBIDDEN as $locale => $rules) {
    $path = "{$locale_root}/{$locale}/LC_MESSAGES/shop_prefill.po";
    if (!is_file($path)) {
        check(false, "{$locale}: файл {$path} не найден");
        continue;
    }

    $scanned = 0;
    foreach (parsePo($path) as $key => $text) {
        if (!isStorefrontDialogKey($key)) {
            continue;
        }
        $scanned++;

        foreach ($rules as [$pattern, $why]) {
            check(!preg_match($pattern, $text), "{$locale} {$key}: без запрещённого слова ({$why})");
        }
    }

    // Пустой обход — самый тихий способ, которым такой тест перестаёт что-либо проверять
    check($scanned >= 15, "{$locale}: просмотрено {$scanned} витринных строк диалогов (ожидаем не меньше 15)");
}

echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";

exit($failures === 0 ? 0 : 1);
