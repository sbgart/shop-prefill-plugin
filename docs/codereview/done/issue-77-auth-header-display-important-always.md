# Issue 77 — Плагин безусловно навязывает `display: inline !important` элементам шапки авторизации

**Статус:** ✅ Закрыта 20.08.2026 — применён вариант 1
**Приоритет:** 🟠 Средний (ломает вёрстку чужой темы даже при выключенной функции)
**Сложность фикса:** ⚡ Минутный
**Файлы:** `css/frontend.css` (и собранный `css/frontend.min.css`), `lib/classes/hooks/shopPrefillPluginFrontendHooks.class.php` (`isAuthHeaderHidden`, `initializeFrontendAssets`, `buildAuthHeaderHiddenRule`), `lib/classes/view/shopPrefillPluginAssetsManager.class.php` (`init`, `generateCssVariablesFile`)

## Решение

Применён вариант 1 из рекомендации: правило убрано из статического `frontend.css`/`frontend.min.css` целиком (селектор там больше не встречается) и теперь генерируется в тот же динамический файл `variables_{hash}.css`, что и остальные CSS-переменные плагина — но **только** когда `isAuthHeaderHidden()` истинно. `!important` в правиле сохранён осознанно: без него нет гарантии, что оно перебьёт специфичность темы, когда реально нужно скрыть шапку.

`shopPrefillPluginAssetsManager::init()`/`generateCssVariablesFile()` получили параметр `$extra_css` — произвольный CSS, дописываемый после блока `:root` перед хешированием содержимого; кэш по content-hash продолжает работать корректно (включено/выключено → разный хэш → разный файл). CSS-переменная `--prefill-auth-header-display` убрана за ненадобностью — значение `none` теперь зашито прямо в сгенерированное правило.

Проверено в браузере на `/order/`: при включённой настройке (`zen.groups.customer.hide_auth_header`) правило есть в `variables_*.css` и `display: none !important` применяется; при выключенной — правила в файле нет вовсе, и `.wa-contact-name`/`.wa-logout-link` получают собственный `display` от темы (`inline`/`inline-block`), а не одинаковый принудительный `inline`.

## Проблема

```css
.wa-step-auth-section .wa-section-header .wa-contact-name,
.wa-step-auth-section .wa-section-header .wa-logout-link {
    display: var(--prefill-auth-header-display, inline) !important;
}
```

Переменная `--prefill-auth-header-display` задаётся из PHP **только** когда функция включена:

```php
if ($this->isAuthHeaderHidden()) {
    $css_variables['prefill-auth-header-display'] = 'none';
}
```

Во всех остальных случаях срабатывает фолбэк `inline` — но правило-то применяется всегда, и с `!important`. То есть плагин молча переписывает `display` двум элементам ядра на каждой витрине, где он установлен, независимо от настроек Zen Mode и `hide_auth_header`.

Если тема магазина выводит имя покупателя как `display:flex` (типично для строки «аватар + имя») или `display:block`, плагин ломает эту раскладку — и разбираться владелец магазина будет с плагином, а не с темой.

Усугубляется тем, что `frontend.css` грузится на **всех** страницах витрины (см. [issue-64](issue-64-assets-loaded-on-every-page.md)) — правило висит и там, где секции авторизации нет.

## Рекомендация

1. Убрать правило из общего `frontend.css` и выдавать его **только** когда функция включена — тем же путём, что и сама переменная, то есть в генерируемом файле CSS-переменных:

   ```php
   if ($this->isAuthHeaderHidden()) {
       $css_variables['prefill-auth-header-display'] = 'none';
   }
   ```

   → и в этот же файл дописывать сам селектор.
2. Либо, если правило должно остаться в статике, сменить фолбэк на нейтральный и убрать `!important` из ветки «функция выключена`:

   ```css
   display: var(--prefill-auth-header-display, revert);
   ```

   `revert` вернёт значение из темы, а не навяжет `inline`.
3. Проверить остальной `frontend.css` на правила, задевающие селекторы ядра/темы вне зоны плагина: сейчас это единственное такое место (`grep -nE "^[^{@/]*\{" css/frontend.css | grep -v prefill`), и хорошо бы, чтобы так и осталось.
