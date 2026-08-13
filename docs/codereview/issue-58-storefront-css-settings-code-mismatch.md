# Issue 58 — CSS витрины: настройки берутся у одной витрины, код файла — у другой

**Статус:** ✅ Закрыта
**Приоритет:** 🟠 Средний (видимый на витрине баг + мусор в `wa-data`)
**Сложность фикса:** 🔧 Небольшой
**Файл:** `lib/shopPrefill.plugin.php:341-366` (`resolveStorefrontCssUrl`), `lib/shopPrefill.plugin.php:163-181` (`getStorefrontSettings`)

## Проблема

`getStorefrontSettings()` содержит фоллбэк: если у конкретной витрины `active = false`, возвращаются **глобальные** настройки `'*'` целиком — включая `styles.custom_css` и `update_time`.

`resolveStorefrontCssUrl()` берёт настройки через этот фоллбэк, а код витрины — **напрямую у текущей**:

```php
$settings   = $this->getStorefrontSettings();          // может быть от витрины '*'
$custom_css = $settings['styles']['custom_css'] ?? '';

$code = $this->getStorefrontProvider()->getCurrentStorefront()->getCode();  // всегда текущая витрина

if (!$css_manager->fileExists($code)) {
    $css_manager->saveFile($code, $custom_css);        // глобальный CSS под кодом конкретной витрины
}

$url = $css_manager->getPublicUrl($code, (int) ($settings['update_time'] ?? 0));
```

Источник данных и идентификатор файла расходятся. Фоллбэк размазан по двум местам, и второе о нём не знает.

## Когда проявляется

Ровно в той конфигурации, которая описана в самом плагине как основная: плагин включён глобально (`'*'` → `active = true`), конкретные витрины не активированы вручную (`active = false`).

## Последствия

**1. Правки глобального CSS не доезжают до витрин.** Файл `frontend_{code}.css` создаётся один раз — при первом запросе с витрины. Дальше `fileExists($code)` → `true`, и `saveFile()` больше не вызывается. Админ меняет `custom_css` в общих настройках → `syncCssFile()` перезаписывает только файл для кода `'*'`, per-storefront копия остаётся со **старым содержимым навсегда** (до ручной чистки `wa-data`). При этом `update_time` в URL обновляется — то есть cache-buster честно меняет URL, а отдаётся по нему устаревший CSS. Диагностируется тяжело: кажется, что «кеш где-то залип».

**2. Мусорные копии в `wa-data`.** На каждую витрину создаётся дубликат одного и того же глобального CSS. Усугубляет [issue-57 п.3](issue-57-minor-robustness-findings.md) (сгенерированные файлы никогда не удаляются).

## Рекомендация

Резолвить витрину **один раз** и брать у одного и того же объекта и настройки, и код. Вынести фоллбэк в единственное место — «эффективная витрина» запроса:

```php
private function getEffectiveStorefront(): shopPrefillPluginStorefront
{
    if (self::$effective_storefront === null) {
        $provider   = $this->getStorefrontProvider();
        $storefront = $provider->findCurrentStorefront();

        // Витрины нет (бэкенд/API/CLI) или она не активна → глобальные настройки '*'
        if ($storefront === null || !$storefront->getSettings()['active']) {
            $storefront = $provider->getGlobalStorefront();
        }

        self::$effective_storefront = $storefront;
    }

    return self::$effective_storefront;
}
```

Дальше:

- `getStorefrontSettings()` → `getEffectiveStorefront()->getSettings()`;
- `resolveStorefrontCssUrl()` → `getEffectiveStorefront()->getCode()`;
- `clearStorefrontSettingsCache()` сбрасывает и `$effective_storefront`.

При фоллбэке витрина получает URL файла `frontend_*.css` — того самого, который синхронизируется в `syncCssFile('*')` при сохранении общих настроек. Дубликаты не создаются, устаревшего содержимого не остаётся.

## Как исправлено

`resolveStorefrontCssUrl()` берёт настройки и код у одного объекта — `shopPrefillPlugin::getEffectiveStorefront()`. Фоллбэк живёт только внутри него.

Проверено на деве (глобальные `active = true`, обе витрины `active = false`):

| Сценарий | URL на витрине | Файлы в `wa-data` |
| --- | --- | --- |
| глобальный `custom_css` = V1 | `frontend__.css?1786281835` | только `frontend__.css` |
| глобальный `custom_css` → V2 | `frontend__.css?1786281853`, отдаётся **V2** | только `frontend__.css` |
| витрина активна + свой `custom_css` | `frontend_d2EtZGV2LmxvYy9zaG9wLyo_.css?…` | + свой файл витрины |
| вторая витрина (неактивна) | `frontend__.css?1786281853` | без дубликата |

## Связанные

- [issue-49](issue-49-fatal-storefront-null-backend-order-create.md) — та же причина (null-витрина и размазанный фоллбэк). `getEffectiveStorefront()` закрывает обе; если issue-49 чинится точечным guard'ом, этот баг остаётся и правится отдельно.
- [issue-57 п.1](issue-57-minor-robustness-findings.md) — `getStorefront('*')` ищет витрину в коллекции и может вернуть `null`; `getGlobalStorefront()` должен её конструировать.
- [issue-57 п.3](issue-57-minor-robustness-findings.md) — накопление файлов в `wa-data`.
