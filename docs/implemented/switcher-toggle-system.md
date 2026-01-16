# Универсальная система сворачивания блоков через переключатели

## Описание

Плагин использует **единую универсальную систему** для управления видимостью блоков через переключатели (switcher). Вместо написания специфичной логики для каждого случая, система работает **декларативно** через data-атрибуты в HTML.

**История**: Ранее существовали две отдельные системы (`data-toggle-target` и метод `collapse()` с `data-for`). Они были объединены в одну универсальную систему для уменьшения дублирования кода.

## Как это работает

### 1. HTML: Задаём связь через data-атрибуты

Система поддерживает **два способа** задания связи (для обратной совместимости):

#### Способ 1: `data-toggle-target` (рекомендуется)

```html
<!-- Переключатель с указанием целевого блока -->
<span class="switch" data-type="switcher" data-toggle-target="my-block-id">
  <input type="checkbox" name="enabled" value="true" checked>
</span>

<!-- Целевой блок по id -->
<div id="my-block-id">
  <!-- Содержимое блока -->
</div>

<!-- Или по data-block -->
<div data-block="my-block-id">
  <!-- Содержимое блока -->
</div>
```

#### Способ 2: `data-for` (для обратной совместимости)

```html
<!-- Старый способ через data-type="switcher,collapse" -->
<span class="switch" data-type="switcher,collapse" data-for="css">
  <input type="checkbox" name="use_custom_css" value="true">
</span>

<!-- Целевой блок по data-id -->
<div data-id="css">
  <!-- Содержимое блока -->
</div>
```

### 2. JavaScript: Автоматическая инициализация

Метод `MinorderSettings.prototype.switcher()` автоматически:

1. Находит все переключатели с `data-type="switcher"`
2. Проверяет наличие атрибута `data-toggle-target` или `data-for`
3. Ищет целевой блок в порядке приоритета:
   - По `data-block` внутри `.field` (локальный поиск)
   - По `id` внутри `.field` (локальный поиск)
   - По `data-id` глобально (фолбэк для старой системы)
4. Устанавливает начальное состояние (показать/скрыть) на основе checked состояния
5. Добавляет обработчик для плавного сворачивания/разворачивания
6. Управляет ARIA-атрибутами для accessibility
7. Автоматически вызывает `refreshEditors()` для CodeMirror после анимации

**Никакого дополнительного кода писать не нужно!**

## Преимущества

✅ **DRY (Don't Repeat Yourself)** — одна универсальная функция вместо двух дублирующихся систем  
✅ **Декларативность** — поведение задаётся через HTML, а не через JS  
✅ **Расширяемость** — легко добавить новые сворачиваемые блоки  
✅ **Обратная совместимость** — поддержка старого API (`data-for`)  
✅ **Maintainability** — проще поддерживать и отлаживать  
✅ **Accessibility** — автоматическое управление `aria-expanded` и `aria-hidden`  
✅ **CodeMirror integration** — автоматическое обновление редакторов после анимации  
✅ **Меньше кода** — удалено ~95 строк дублирующейся логики из метода `collapse()`

## Сравнение до и после объединения

### До объединения (2 системы):

**Система 1**: `data-toggle-target` — для dynamic-appearance
- Метод: встроено в `switcher()` 
- Поиск: локальный в `.field`
- Анимация: `slideDown(200)` / `slideUp(200)`

**Система 2**: `data-for` с методом `collapse()` — для других блоков  
- Метод: отдельный `collapse()` (**95 строк кода**)
- Поиск: глобальный по `data-id`
- Анимация: `show("fast")` / `hide("fast")`

**Проблемы**:
- ❌ Дублирование функциональности
- ❌ Разные API для одной задачи
- ❌ Больше кода для поддержки
- ❌ Потенциальные конфликты при глобальном поиске

### После объединения (1 система):

**Единая универсальная система** в методе `switcher()`:
- ✅ Поддерживает оба API (`data-toggle-target` и `data-for`)
- ✅ Умный поиск: локальный → глобальный (фолбэк)
- ✅ Единая анимация: `slideDown(200)` / `slideUp(200)`
- ✅ Автоматическая интеграция с CodeMirror
- ✅ Меньше кода (~40 строк вместо 95+40)  

## Примеры использования

### Пример 1: Dynamic Appearance Toggle (новый способ)

```html
{* templates/actions/settings/blocks/fields/includes/DynamicAppearanceToggle.html *}
<div class="dynamic-appearance-enabled-wrapper">
  <span class="switch" data-type="switcher" data-toggle-target="dynamic-appearance-block">
    <input type="checkbox" name="{$name}[da_enabled]" value="true" checked>
  </span>
  <span>Включить стили</span>
</div>

<div data-block="dynamic-appearance" id="dynamic-appearance-block">
  <!-- Настройки внешнего вида -->
</div>
```

### Пример 2: Custom CSS Toggle (старый способ через data-for)

```html
{* templates/actions/settings/blocks/tabs/Design.html *}
{include file='../fields/SwitcherField.html'
  name="[use_custom_css]"
  label='Пользовательский CSS'
  value=ifset($settings['use_custom_css'])
  collapse='css'}

<div data-id="css">
  {include file='../fields/EditorField.html'
    name="[css]"
    value=ifset($settings['css'])
    mode='css'}
</div>
```

**Примечание**: Оба способа работают одинаково! Старый способ поддерживается для обратной совместимости.

### Пример 3: Любой новый сворачиваемый блок

```html
<!-- Переключатель для продвинутых настроек -->
<span class="switch" data-type="switcher" data-toggle-target="advanced-settings">
  <input type="checkbox" name="show_advanced" value="true">
</span>
<span>Показать продвинутые настройки</span>

<!-- Блок с продвинутыми настройками -->
<div id="advanced-settings" style="display: none;">
  <!-- Продвинутые настройки -->
</div>
```

## Алгоритм работы

1. **Поиск целевого блока**: Система ищет блок в родительском `.field` контейнере
2. **Приоритет поиска**:
   - Сначала по `data-block="[target]"`
   - Если не найден, то по `id="[target]"`
3. **Анимация**: Используется `slideDown(200)` / `slideUp(200)` для плавности
4. **Accessibility**: Обновляются атрибуты после завершения анимации

## Код реализации

```javascript
// Метод: MinorderSettings.prototype.switcher()
// Файл: js/minorder.settings.js:24-72

$switchers.each(function () {
  var $switcher = $(this);
  var toggleTarget = $switcher.data("toggle-target");
  var $targetBlock = null;

  if (toggleTarget) {
    var $field = $switcher.closest(".field");
    if ($field.length > 0) {
      $targetBlock = $field.find('[data-block="' + toggleTarget + '"]');
      if ($targetBlock.length === 0) {
        $targetBlock = $field.find("#" + toggleTarget);
      }
    }
  }

  $switcher.waSwitch({
    change: function (active, wa_switch) {
      if ($targetBlock && $targetBlock.length > 0) {
        var $checkbox = $switcher.find('input[type="checkbox"]');
        
        if (active) {
          $targetBlock.slideDown(200, function () {
            $targetBlock.attr("aria-hidden", "false");
          });
          if ($checkbox.length > 0) {
            $checkbox.attr("aria-expanded", "true");
          }
        } else {
          $targetBlock.slideUp(200, function () {
            $targetBlock.attr("aria-hidden", "true");
          });
          if ($checkbox.length > 0) {
            $checkbox.attr("aria-expanded", "false");
          }
        }
      }
    },
  });
});
```

## Миграция старого кода

### Было (специфичная логика в каждом методе):

```javascript
MinorderSettings.prototype.dynamicAppearance = function () {
  // ... 25+ строк кода для инициализации переключателя
  var $switcher = $field.find('[data-type="switcher"]');
  $switcher.waSwitch({
    change: function (active, wa_switch) {
      if (active) {
        $block.slideDown(200);
      } else {
        $block.slideUp(200);
      }
    },
  });
  // ... остальная логика
};
```

### Стало (универсальная система):

```javascript
MinorderSettings.prototype.dynamicAppearance = function () {
  // Переключатель инициализируется автоматически в switcher()
  // благодаря data-toggle-target="dynamic-appearance-block"
  
  // Только специфичная логика для dynamic appearance
  var $dynamicAppearanceInputs = $block.find("[data-dynamic-appearance]");
  // ... работа со стилями
};
```

## Будущие расширения

Систему можно легко расширить дополнительными опциями:

```html
<!-- Пример возможных расширений -->
<span class="switch" 
      data-type="switcher" 
      data-toggle-target="my-block"
      data-toggle-animation="fade"    <!-- Тип анимации: slide / fade / none -->
      data-toggle-duration="300"      <!-- Длительность анимации в мс -->
      data-toggle-class="active">     <!-- Добавление класса вместо show/hide -->
```

## История изменений

- **2026-01-04**: Реализована универсальная система на основе data-атрибутов
- **2026-01-04**: Удалена специфичная логика из метода `dynamicAppearance()`
- **2026-01-04**: Добавлена поддержка ARIA-атрибутов для accessibility

