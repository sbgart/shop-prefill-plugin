# Issue 75 — Zen Mode скрывает секции CSS-ом, даже когда кнопка «Изменить» не выведена: чекаут без выхода

**Статус:** ✅ Исправлено
**Приоритет:** 🟠 Средний (см. уточнение в конце документа — реальный блокер только у кастомных тем, потерявших `{foreach $event_hook.*}`)
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

## Решение

Вместо того чтобы связывать два независимых расчёта флагом (issue-73 уже показал, чем плохи статические метки на пере-создаваемом на каждый хук инстансе), CSS вообще перестал считаться отдельно. `generateGroupStyles(string $group)` вызывается изнутри `renderCollapseBlock()` и попадает в возвращаемую строку **только вместе с кнопкой «Изменить»**, в той же ветке `if ($is_collapsed)`:

```php
// shopPrefillPluginZenMode::renderCollapseBlock()
$html = $this->view->fetch('file:' . $template_path);   // блок с кнопкой «Изменить»
return $is_collapsed ? ($this->generateGroupStyles($group) . $html) : $html;
```

Дальше решает ядро: печатает хук секции — печатается и CSS вместе с кнопкой; выбрасывает (`{if empty($details.disabled)}`, обрезанный `{foreach}` в теме) — выбрасывается и CSS. Ветка «CSS есть, кнопки нет» стала физически невозможна, а не просто маловероятна.

Побочный эффект: раньше `shouldCollapseGroup()` для одной и той же группы считался дважды на разных `$state` — один раз в `buildCollapseBlock()` (свой хук секции), второй раз в confirm-хуке (`getGroupsToCollapse()`). На AJAX-обновлении секции могли разойтись. Теперь расчёт один, решение принимается один раз.

Что изменилось:

- `getGroupsToCollapse()` и `generateAllStyles(array $groups)` — удалены.
- `generateGroupStyles(string $group)` (приватный, в `shopPrefillPluginZenMode`) генерирует CSS одной группы с уникальным `id="prefill-zen-styles-{group}"` (был один общий `id="prefill-zen-styles"` на всех — если бы когда-то понадобилось трогать конкретный тег через JS, коллизия id была бы гарантирована при заполненных ≥2 группах).
- `handleCheckoutRenderConfirm()` больше не генерирует CSS — вызов `renderZenModeConfirmStyles()` убран.
- `<link rel="stylesheet" href=".../zenmode.css">` перенесён из `handleCheckoutRenderAuth()` (единственного места, где он раньше выводился) в `buildZenModeGroupBlock()` — теперь тег едет с каждым фактически выведенным блоком группы (auth/details/payment), а не только с auth. Раньше при `hide_auth_header`/выключенной группе `customer`, но включённой `delivery`, стили Zen Mode всё равно зависели от того, отрисовалась ли секция auth. Побочно решает и рекомендацию №3 из первой версии документа (JS-подстраховка от «стиль без кнопки») — сценарий, для которого она была нужна, больше не существует.

## Проверено при ревью подхода

- **Фолбэк «вывести блок delivery в `shipping`, если не вывелся в `details`»** (была рекомендация №2) сценарий `shipping.used = false` не решает: тем же условием `empty($config['shipping']['used'])` дизейблятся все три шага — region, shipping и details (`shopCheckoutRegionStep`, `shopCheckoutShippingStep`, `shopCheckoutDetailsStep`), и вывод хука в `shipping.html` тоже завёрнут в `{if empty($shipping.disabled)}`. Перекладывать блок было некуда. С текущим решением фолбэк не нужен как багфикс — CSS просто не появится, если ни одна секция группы не отрисовалась.
- **Уточнение приоритета.** При `shipping.used = false` ядро вешает `display:none` на все три секции группы `delivery` целиком (весь `<section>`, не только форму) — покупатель их не видит, кроме одной: `ZenModeToggle.forceDetailSectionVisible()` безусловно снимает inline-стиль с `#wa-step-details-section` на каждом `wa_order_form_ready` (`js/modules/OrderFormManager.js`). То есть пустую секцию без кнопки показывал сам плагин, но заказ оформлялся — блокера не было. Реальный блокер (поля скрыты, развернуть нечем, заказ невозможен) — только у кастомной темы, потерявшей `{foreach $event_hook.details}` при кастомизации `order.details.html`.

## Тест

В настройках чекаута выключить доставку (`shipping.used = false`), открыть `/order/` при включённом Zen — секции должны остаться рабочими и не содержать «сирот»-CSS. Плюс: включить Zen для всех трёх групп на обычном магазине (доставка используется), пройти цикл разворачивания/сворачивания каждой группы, убедиться что на странице ровно столько `<style id="prefill-zen-styles-*">`, сколько свёрнутых групп, и что удаление кнопки (эмуляция обрезанной темы) гарантированно убирает и CSS.
