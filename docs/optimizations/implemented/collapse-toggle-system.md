# Система сворачивания/разворачивания блоков (Collapse Toggle System)

**Дата внедрения:** 2026-01-04  
**Статус:** Реализовано

## Проблема

В шаблоне `EditorField.html` при клике на ссылку с `data-type="collapse"` не открывался блок с переменными окружения. Отсутствовал JavaScript-обработчик для элементов типа `collapse`.

## Решение

Добавлен новый метод `MinorderSettings.prototype.collapse()` для обработки элементов с `data-type="collapse"`.

### Изменения в файлах

#### 1. JavaScript: `js/minorder.settings.js`

Добавлен метод `collapse()`:

- Находит все элементы с `data-type="collapse"`
- Устанавливает начальное состояние на основе `aria-expanded`
- Обрабатывает клики для переключения видимости целевых блоков
- Использует `slideUp`/`slideDown` для плавной анимации
- Управляет классом `.show` и атрибутами `aria-expanded`, `aria-hidden`

Метод вызывается в `MinorderSettings.initComponents()` между `toggle()` и `editor()`.

#### 2. CSS: `css/minorder.settings.css`

Обновлены стили для `.vars-hint`:

- По умолчанию `display: none`
- При добавлении класса `.show` — `display: block`

### Использование

В шаблонах используется следующая структура:

```html
<a href="javascript:void(0);" data-type="collapse" data-for="unique-id" aria-expanded="false"> Открыть блок </a>

<div data-id="unique-id" class="vars-hint">Скрытый контент</div>
```

### Особенности реализации

1. **Поиск целевого блока**: использует `data-for` для связи toggle с целевым блоком через `data-id`
2. **Accessibility**: правильно управляет атрибутами `aria-expanded` и `aria-hidden`
3. **Анимация**: плавное сворачивание/разворачивание через jQuery `slideUp`/`slideDown`
4. **Класс `.show`**: добавляется/удаляется для управления видимостью через CSS

## Преимущества

- ✅ Простая в использовании система для любых сворачиваемых блоков
- ✅ Соответствует принципам accessibility (ARIA-атрибуты)
- ✅ Плавная анимация
- ✅ Минимальный код в шаблонах
- ✅ Переиспользуемое решение

## Связанные файлы

- `js/minorder.settings.js` — метод `collapse()`
- `css/minorder.settings.css` — стили для `.vars-hint`
- `templates/actions/settings/blocks/fields/EditorField.html` — использование системы
