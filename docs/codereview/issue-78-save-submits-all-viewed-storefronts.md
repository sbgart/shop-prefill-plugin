# Issue 78 — «Сохранить» отправляет настройки всех витрин, которые администратор успел открыть

**Статус:** ⬜ Открыта
**Приоритет:** 🟢 Низкий (idempotent в норме), 🟠 при параллельном редактировании
**Сложность фикса:** 🔧 Небольшой
**Файлы:** `js/components/prefill-storefront-select.js` (`createStorefrontContainer`, `showStorefrontContainer`), `templates/actions/settings/Settings.html`, `lib/shopPrefill.plugin.php` (`saveSettings`)

## Проблема

Web-компонент кеширует загруженные блоки витрин и **прячет** их, а не удаляет:

```js
hideAllStorefrontContainers(wrapper) {
    …containers.forEach((container) => { container.style.display = "none"; });
}
```

Все эти контейнеры живут внутри `#prefill-storefront-content`, который находится **внутри формы**:

```smarty
<form id="plugins-settings-form" action="?module=plugins&id={$plugin_id}&action=save" method="POST">
    …
    <div class="prefill-storefront-content" id="prefill-storefront-content"></div>
```

Скрытые через `display:none` поля браузер отправляет наравне с видимыми. Имена полей содержат код витрины (`shop_prefill[storefront][<код>][…]`), поэтому в POST уезжают **все** просмотренные витрины, а `saveSettings()` честно сохраняет каждую:

```php
foreach ($settings['storefront'] as $storefront_code => $storefront_settings) {
    …
    $storefront->saveSettings($storefront_settings);
}
```

## Последствия

1. **Потерянные изменения.** Значения в скрытых блоках — снимок на момент их загрузки. Если за это время настройки витрины изменил другой администратор, другая вкладка или debug-эндпоинт `prefill/toggle-zen`, изменения молча откатываются к снимку.
2. **Стоимость сохранения умножается.** `StorefrontSettingProvider::saveSettings()` делает SELECT + INSERT/UPDATE на каждый лист дерева (см. [issue-74 §5](issue-74-minor-findings-pass-2.md)). Открыли 5 витрин — заплатили за 5 полных сохранений.
3. **Побочные записи на диск.** Для каждой отправленной витрины срабатывает `syncCssFile()` → перезапись per-storefront CSS-файла, а `update_time` обновляется → меняется cache-buster → покупатели заново качают CSS витрин, которых никто не трогал.

## Рекомендация

1. Перед сабмитом отключать поля скрытых контейнеров:

   ```js
   $('#plugins-settings-form').on('submit', function () {
       document.querySelectorAll('[data-storefront-code]').forEach((c) => {
           const off = c.style.display === 'none';
           c.querySelectorAll('input, select, textarea').forEach((el) => { el.disabled = off; });
       });
   });
   ```

   (`disabled`-поля не отправляются; альтернатива — `cache="false"` на компоненте, но тогда теряется несохранённый ввод при переключении витрины.)
2. Или сохранять по одной витрине через отдельный AJAX-эндпоинт — заодно уходит проблема №2.
3. В любом случае имеет смысл предупреждать при переключении витрины, если в текущей есть несохранённые правки: сейчас они молча уезжают в общий сабмит, и понять, что именно сохранилось, невозможно.
