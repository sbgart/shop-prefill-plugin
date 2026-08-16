# Issue 77 — Плагин безусловно навязывает `display: inline !important` элементам шапки авторизации

**Статус:** ⬜ Открыта
**Приоритет:** 🟠 Средний (ломает вёрстку чужой темы даже при выключенной функции)
**Сложность фикса:** ⚡ Минутный
**Файлы:** `css/frontend.css` (и собранный `css/frontend.min.css`), `lib/classes/hooks/shopPrefillPluginFrontendHooks.class.php` (`isAuthHeaderHidden`, `initializeFrontendAssets`)

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
