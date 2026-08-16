# Issue 75 — Zen Mode скрывает секции CSS-ом, даже когда кнопка «Изменить» не выведена: чекаут без выхода

**Статус:** ⬜ Открыта
**Приоритет:** 🔴 Высокий (магазин теряет заказы, покупатель не может оформить)
**Сложность фикса:** 🔧 Небольшой
**Файлы:** `lib/classes/zenmode/shopPrefillPluginZenMode.class.php` (`getGroupsToCollapse`, `generateAllStyles`), `lib/classes/hooks/shopPrefillPluginCheckoutHooks.class.php` (`handleCheckoutRenderShipping`, `handleCheckoutRenderDetails`)

## Проблема

Сворачивание состоит из двух независимых частей, и они не связаны между собой:

1. **Кнопка «Изменить»** для группы `delivery` выводится **только** в `checkout_render_details`:

   ```php
   public function handleCheckoutRenderDetails(array &$params): string
   {
       return $this->buildZenModeGroupBlock('delivery', $state, 'checkoutRenderDetails') . …
   }
   ```

2. **CSS, который прячет содержимое**, считается заново в `checkout_render_confirm` и не знает, вывелась ли кнопка:

   ```php
   $groups_to_collapse = $this->zen_mode->getGroupsToCollapse($state);   // независимый расчёт
   return $this->zen_mode->generateAllStyles($groups_to_collapse);
   // → .wa-step-region-section  … form > *:not(.wa-plugin-hook){display:none!important}
   // → .wa-step-shipping-section … то же
   // → .wa-step-details-section  … то же
   ```

## Когда кнопка не выводится

Ядро печатает результат хука `checkout_render_details` внутри условия (`wa-apps/shop/templates/actions/frontend/order/form/details.html:11`):

```smarty
<form>
    {if empty($details.disabled)}
        …
        {foreach $event_hook.details as $_}<div class="wa-plugin-hook">{$_}</div>{/foreach}
    {/if}
</form>
```

А `disabled` ставит сам шаг, когда в настройках чекаута выключена доставка (`shopCheckoutDetailsStep::process()`, строка 57):

```php
if (empty($config['shipping']['used'])) {
    $result = $this->addRenderedHtml(['disabled' => true], $data, []);
```

`shipping.used = false` — штатная конфигурация магазина: цифровые товары, услуги, продажа только самовывозом без адреса. То есть на таком магазине:

- хук `checkout_render_details` отрабатывает, но его HTML ядро выбрасывает;
- `checkout_render_confirm` отрабатывает и выдаёт CSS, прячущий содержимое `region` / `shipping` / `details`;
- покупатель видит пустые блоки без единой кнопки, развернуть их нечем.

Второй, более частый путь к тому же результату — **тема магазина**. Все шесть шаблонов шагов в ядре печатают `{$event_hook.<step>}`, но тема вправе переопределить `order.details.html` (см. `shopCheckoutStep::renderHtml()` — сначала ищется `$theme_template_path`). Тема, где этот `foreach` потерян при кастомизации, даёт ровно ту же картину. Для плагина, который ставится на чужие темы, это не гипотетический сценарий.

Косвенное подтверждение, что фолбэк задумывался: в PHPDoc самого хука он описан, но в коде его нет —

```php
/**
 * Хук срабатывает перед формированием HTML-кода шага … «выбор способа доставки».
 * Также может выводить блок управления zen-режимом для группы delivery, если details пустой/не существует.
 */
public function checkoutRenderShipping(&$params)
```

а `handleCheckoutRenderShipping()` вызывает только `renderSectionErrorsAndDebug()`.

Существующий костыль `ZenModeToggle.forceDetailSectionVisible()` (снимает `display:none` с `#wa-step-details-section`) решает соседнюю задачу и здесь не помогает: секцию он покажет, а кнопку не вернёт.

## Рекомендация

1. Связать две части: `buildCollapseBlock()` помечает группу как «блок реально отрисован» (статическая метка — экземпляр плагина создаётся заново на каждый хук, см. [issue-73](issue-73-stale-plugin-singleton.md)), а `generateAllStyles()` в confirm-хуке стилизует **только помеченные** группы. Порядок шагов гарантирован: `confirm` идёт последним в `shopCheckoutConfig::getCheckoutSteps()`.
2. Дополнительно реализовать обещанный фолбэк: если блок delivery не вывелся в `details`, вывести его в `shipping`.
3. Подстраховаться на клиенте: если на странице есть `#prefill-zen-styles`, но нет ни одного `.js-prefill-zen-toggle` для группы — снимать стиль. Дешёвая защита от кастомных тем.
4. Тест: в настройках чекаута выключить доставку (`shipping.used = false`), открыть `/order/` при включённом Zen — секции должны остаться рабочими.
