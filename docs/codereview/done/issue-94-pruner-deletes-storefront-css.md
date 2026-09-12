# Issue 94 — Уборщик устаревших ассетов удаляет пер-витринный `frontend_{code}.css` вместе с мусором

**Статус:** ✅ Исправлена 09.09.2026, в день находки
**Приоритет:** 🟠 Важно до продажи
**Сложность фикса:** 🔧 Небольшой
**Файлы:** `lib/classes/view/shopPrefillPluginStaleFilePruner.class.php` (`prune()`), `lib/classes/view/shopPrefillPluginAssetsManager.class.php` (`generateCssVariablesFile()`), `lib/classes/css/shopPrefillPluginCssManager.class.php` (`getPublicDir()`)

## Как это работает сейчас

Два независимых механизма пишут в **один и тот же каталог**.

`AssetsManager` кладёт туда сгенерированный файл CSS-переменных и сразу подчищает старьё:

```php
// shopPrefillPluginAssetsManager.class.php::generateCssVariablesFile()
$css_public_dir = wa()->getDataPath("plugins/{$this->plugin_id}/css/", true, 'shop');

if (!file_exists("{$css_public_dir}{$css_variables_filename}")) {
    file_put_contents("{$css_public_dir}{$css_variables_filename}", $css_variables_map);
    $this->getPruner()->prune($css_public_dir, $css_variables_filename, self::PRUNE_TTL_SECONDS);
}
```

`CssManager` кладёт туда же пер-витринный файл с переопределениями администратора:

```php
// shopPrefillPluginCssManager.class.php
public function getFilePath(string $storefront_code): string
{
    $safe_code = $this->sanitizeCode($storefront_code);
    return "{$this->getPublicDir()}frontend_{$safe_code}.css";
}

private function getPublicDir(): string
{
    return wa()->getDataPath("plugins/{$this->plugin_id}/css/", true, 'shop');
}
```

Пути совпадают дословно. А уборщик не фильтрует по имени — он удаляет **всё** старше TTL, кроме
только что записанного файла:

```php
// shopPrefillPluginStaleFilePruner.class.php::prune()
$paths = glob(rtrim($dir, '/') . '/*');
foreach ($paths as $path) {
    if (basename($path) === $except_filename || !is_file($path)) {
        continue;
    }
    $mtime = filemtime($path);
    if ($mtime !== false && $mtime < $threshold) {
        unlink($path);
    }
}
```

`$except_filename` — это `variables_<hash>.css`, поэтому `frontend_<код витрины>.css` под исключение
не попадает никогда.

**Воспроизведено (09.09.2026)** на копии каталога, вызовом самого класса (он не зависит от рантайма
Webasyst):

```
ДО:     frontend_ccce8c208c86784e78817b593a93faa5.css (mtime 01.07.2026)
        variables_new.css
        → prune($dir, 'variables_new.css', 30 суток)
ПОСЛЕ:  variables_new.css
```

Каталог стенда: `wa-data/public/shop/plugins/prefill/css/`, самый старый файл там сейчас от
09.08.2026 — то есть TTL (30 суток) уже перевален, механизм живой.

## Что из этого следует

Условия совпадения: у витрины задан `styles.custom_css` (файл на диске существует), он не
перезаписывался дольше 30 суток, и в этом запросе понадобился **новый** набор CSS-переменных — то
есть администратор поменял акцентный цвет, размер иконок Zen или включил/выключил скрытие шапки
авторизации, либо покупатель пришёл на другую витрину с другими цветами.

Последовательность внутри одного запроса:

1. `resolveStorefrontCssUrl()` видит файл на месте и возвращает его URL — он уходит в `<link>`;
2. `AssetsManager::init()` пишет новый `variables_<hash>.css` и зовёт `prune()`;
3. `prune()` удаляет `frontend_<код>.css` — его mtime старый.

Покупатель получает страницу со ссылкой на только что удалённый файл → 404, оформление заказа
рисуется без пользовательских стилей витрины. То же увидят все, кому отдан закэшированный HTML с этой
ссылкой.

Отказ самоизлечивается: на следующем рендере `resolveStorefrontCssUrl()` обнаружит пропажу и
пересоздаст файл из БД (`if (!$css_manager->fileExists($code)) { $css_manager->saveFile(...); }`).
Поэтому это не потеря данных — источник истины в `shop_prefill_settings`. Но диагностируется
отвратительно: «стили витрины иногда слетают на один заход» без единой записи в логе (удаление
уборщик не логирует вовсе).

Сегодня на стенде не проявляется по единственной причине: `custom_css` пуст у всех витрин, файлов
`frontend_*.css` в каталоге нет.

## Как исправлено

Взят вариант 1 в чуть более строгой форме: вместо префикса уборщик получил **glob-маску своих
файлов** — она подставляется прямо в `glob()`, так что чужие файлы он больше не видит вовсе, а не
отсеивает их после выборки.

```php
public function prune(string $dir, string $own_files_glob, string $except_filename, int $ttl_seconds): void
{
    $paths = glob(rtrim($dir, '/') . '/' . $own_files_glob);
```

Маски объявлены константами у единственного вызывающего — `AssetsManager`: `variables_*.css` для
каталога `css/` и `*.js` для `js/`. Параметр обязательный: у уборщика больше нет режима «убираю всё,
что тут лежит», поэтому следующий механизм, который решит писать в общий каталог, не унаследует эту
же проблему молча. Маска `*.js` строже пустого префикса из рекомендации — в `js/` сегодня чужих
файлов нет, но исходная ошибка была именно в предположении «каталог мой целиком».

Тест: случай 7 в `tests/StaleFilePrunerTest.php` — `frontend_ccce8c208c86784e78817b593a93faa5.css`
со старым mtime лежит рядом с устаревшим `variables_old.css` и свежим `variables_new.css`; после
`prune()` пер-витринный файл на месте, свой устаревший удалён, только что записанный не тронут.
Заодно уточнены существующие случаи: маска `*` там, где проверяется guard `is_file()` (иначе
подкаталог не попадал бы в выборку и случай 4 перестал бы что-либо проверять). Все 19 тестов
плагина зелёные.

## Рекомендация (исходная)

1. Сузить уборку до собственных файлов уборщика. Самое дешёвое — передать в `prune()` префикс
   (`variables_`, `''` для каталога `js/`) и пропускать всё, что ему не соответствует. Уборщик
   останется без зависимостей от рантайма, тест `StaleFilePrunerTest` дополняется одним случаем.
2. Альтернатива, если не хочется трогать сигнатуру: складывать генерируемые файлы в подкаталог
   (`css/generated/`) и убирать только его. Дороже — меняются публичные URL.
3. Тест: положить в каталог `frontend_X.css` со старым mtime, прогнать `prune()`, убедиться, что файл
   на месте. Плюс сценарий в TEST-PLAN: задать `custom_css`, состарить файл (`touch -t`), сменить
   акцентный цвет, открыть `/order/` и проверить, что `frontend_X.css` жив и отдаётся 200.

## Связанное

[issue-57](issue-57-minor-robustness-findings.md) §3 — ради чего уборщик появился.
[issue-76](issue-76-custom-css-replaces-plugin-stylesheet.md) — откуда взялся пер-витринный файл
переопределений.
Правило B3 в [RULES.md](../concept/RULES.md) — витрина берётся из одного объекта; здесь нарушена не
она, а изоляция двух механизмов, делящих каталог.
