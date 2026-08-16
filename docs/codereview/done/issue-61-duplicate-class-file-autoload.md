# Issue 61 — Дубликат класса в `lib/classes/fillparams/`: мина в автозагрузке, уезжает в релиз

**Статус:** ✅ Решена
**Приоритет:** 🟠 Средний (риск фатала, тривиальный фикс)
**Сложность фикса:** 🔧 Тривиальный (удалить файл)
**Файл:** `lib/classes/fillparams/shopPrefillPluginFillParamsStorage.class.php`

## Проблема

Файл `shopPrefillPluginFillParamsStorage.class.php` объявляет класс **`shopPrefillPluginGuestHashStorage`** — то же имя, что и соседний `shopPrefillPluginGuestHashStorage.class.php`. Это устаревшая копия: в ней нет `hasGuestHash()`, `clearGuestHash()` и вызовов `shopPrefillPluginLog`.

Проверка по всему `lib/` — расхождение имени файла и класса ровно одно:

```
lib/classes/fillparams/shopPrefillPluginFillParamsStorage.class.php -> объявляет: shopPrefillPluginGuestHashStorage
```

Webasyst строит карту автозагрузки по **имени файла**, а не по содержимому — `waAppConfig::getClasses()`:

```php
$class = $this->getClassByFilename(basename($file));
...
$result[$class] = substr($file, $length + 1);
```

Карта кешируется в `wa-cache/apps/shop/config/autoload.php`, то есть в ней есть запись `shopPrefillPluginFillParamsStorage → .../shopPrefillPluginFillParamsStorage.class.php`, ведущая на файл без такого класса.

## Последствия

Первое же обращение к `shopPrefillPluginFillParamsStorage` даёт одно из двух:

- `waAutoload::autoload()` подключит файл и не найдёт в нём класс → `waException: Not found class [shopPrefillPluginFillParamsStorage] in file [...]`;
- если `shopPrefillPluginGuestHashStorage` к этому моменту уже загружен — `Fatal error: Cannot redeclare class shopPrefillPluginGuestHashStorage`.

Сейчас на класс не ссылается никто (`grep` по `lib/` и `js/` — ноль вхождений). Но `CLAUDE.md` плагина описывал `FillParamsStorage` как живой компонент — «пишет в PHP-сессию (`shop/checkout`)» — то есть прямо приглашал его вызвать. Плюс `lib/config/exclude.php` исключает из архива только `docs` и `*.md`, так что мёртвый файл уезжал клиентам.

## Рекомендация

1. Удалить `lib/classes/fillparams/shopPrefillPluginFillParamsStorage.class.php`.
2. Убрать `FillParamsStorage` из описания групп классов в `CLAUDE.md` (запись про запись в сессию неверна — этим занимается `sessionstorage/`).
3. Сбросить кеш автозагрузки: `rm -rf wa-cache/*/apps/ wa-cache/*/config/`.
4. Подумать о проверке в `/release-pack`: «имя файла `*.class.php` == объявленный в нём класс». Ловится однострочником, а цена пропуска — фатал у клиента.

## Решение

Выполнено по рекомендации 1:1:

1. Удалён `lib/classes/fillparams/shopPrefillPluginFillParamsStorage.class.php`. Проверено: во всём `lib/` и `js/` файл нигде не использовался (`getFillParamsStorage()` в `shopPrefillPlugin` не существует, везде — только `getGuestHashStorage()`).
2. В `CLAUDE.md` плагина из описания группы `fillparams/` убрана запись про `FillParamsStorage`.
3. Кеш автозагрузки сброшен (`wa-cache/*/apps/`, `wa-cache/*/config/`).
4. В `.ai/commands/release-pack.md` добавлен блокирующий шаг 5 — проверка соответствия имени `*.class.php`-файла объявленному в нём классу (`find` + `grep` по `^(class|interface|trait)`), с ссылкой на этот issue как объяснением, почему проверка нужна.

Повторная проверка по всему `lib/` плагина после удаления — расхождений не найдено (0 строк).

**Отдельно, вне рамок этого issue:** в `docs/guides/PREFILL-API.md`, `PREFILL-SETUP.md`, `PREFILL-FUNCTIONALITY.md` есть ссылки на `shopPrefillPluginFillParamsStorage` как на интерфейс с методами `getStoredFillParams()`/`storeFillParams()`/`clearStoredFillParams()` — их нет ни в одном из файлов, включая удалённый. Это отдельный слой устаревшей документации, описывающий вымышленный контракт; не трогали, требует отдельной задачи.
