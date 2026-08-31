# `$delivery_photos_html` — фото пункта выдачи невидимы в Zen-карточке (0×0px)

**Статус:** подтверждено вживую, причина известна, не исправлено.

## Наблюдение

29.08.2026, живой чекаут, способ доставки «Пункт выдачи заказов» (`sd`, `id=37`) с 2 реальными
загруженными фото. Кастомный Zen-шаблон группы `delivery` вставляет `{$delivery_photos_html}` как есть.

Свёрнутая карточка получает корректный HTML (проверено через `innerHTML`) — та же самая разметка,
что и у ядра: `<div class="wa-line wa-photos-section" data-name="…">`, `.wa-action.left/right` со
стрелками, `.wa-photos-list > .wa-photo-wrapper × 2` с правильными `data-image-uri`. Структурно всё
на месте. Но **визуально фотографий нет вообще** — видны только две несвязанные "голые" стрелки одна
под другой, без единой картинки.

Проверено через `getComputedStyle()`/`getBoundingClientRect()` на живом `.wa-photo-wrapper` внутри
`.prefill-zen-summary`:

| Свойство | Ожидается (правило ядра) | Фактически в Zen-карточке |
|---|---|---|
| `display` | `inline-block` | `block` |
| `width` | `calc(25% - 15px)` | `489px` (100% контейнера) |
| `position` | `relative` | `static` |
| `padding-bottom` | `calc(25% * 0.75 - 15px)` (аспект-рейшо) | `0px` |
| `getBoundingClientRect()` | ненулевая высота | **489 × 0px — нулевая высота** |

`.wa-action.left` (стрелка) — ожидается `position: absolute; top: 0; height: 100%; width: ~27px`,
фактически `position: static; width: 489px` (растянута на всю ширину, поэтому стрелки садятся друг
под другом вместо боков галереи). `.wa-photos-section` — ожидается `position: relative; white-space: nowrap`,
фактически `position: static; white-space: normal`.

## Причина

Ядро (`wa-apps/shop/css/frontend/order/form.css`) стилизует галерею строго через явный
предок-селектор:

```css
.wa-order-form-wrapper .wa-step-details-section .wa-details-rates-section
  .wa-photos-section .wa-photos-list .wa-photo-wrapper { width: calc(25% - 15px); ... }
```

Zen-карточка (`templates/zenmode/CollapseBlock.html`) — плоская разметка
`.prefill-zen-collapse-block > .prefill-zen-content > .prefill-zen-summary`, без единого из требуемых
предков (`.wa-step-details-section`, `.wa-details-rates-section`). Правило ядра физически не может
совпасть ни с одним селектором внутри карточки — плагин переиспользует классы ядра, рассчитывая на
его CSS/JS (лайтбокс, скролл, `Details.prototype.initPhotos()`), но эти классы вне их родного дерева
не значат ничего.

С высокой вероятностью то же самое относится и к `$delivery_schedule` (`.wa-day-wrapper`, ожидается
`display: table; table-layout: fixed` от `.wa-order-form-wrapper … .wa-schedule-wrapper .wa-days-wrapper
.wa-day-wrapper`) — но проверить это в живой Zen-карточке не удалось: поле пусто по отдельной,
самостоятельной причине, см.
[zen-delivery-schedule-source-missing.md](zen-delivery-schedule-source-missing.md).

## Воспроизведение

1. Настроить ПВЗ-способ доставки (`sd`/`regionalpickup`/`sydsek`) минимум с одним загруженным фото.
2. В Zen-редакторе шаблонов вставить `{$delivery_photos_html}` в шаблон группы `delivery`.
3. Оформить заказ этим способом, свернуть карточку доставки.
4. DevTools → `.prefill-zen-summary .wa-photo-wrapper` → Computed: `width` растянут на всю карточку,
   `padding-bottom: 0`, итоговая высота элемента — 0px.
