# Issue 16 — Дублирующий класс в `shopPrefillPluginFillParamsStorage.class.php`

**Статус:** ✅ Закрыта  
**Приоритет:** 🔴 Критический  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `fillparams/shopPrefillPluginFillParamsStorage.class.php`

## Проблема

Файл `shopPrefillPluginFillParamsStorage.class.php` содержал `class shopPrefillPluginGuestHashStorage` — то же имя класса, что и в `shopPrefillPluginGuestHashStorage.class.php`. Два разных файла определяли один и тот же класс.

Webasyst-автолоадер загружал первый найденный файл — второй тихо игнорировался. Было непонятно, какая из двух версий класса реально используется в рантайме.

Скорее всего, это был артефакт рефакторинга: файл был переименован, но класс внутри — нет.

## Решение

Проблемный дублирующий класс удален вместе с лишним файлом. В текущем коде больше нет определений `shopPrefillPluginFillParamsStorage`/`FillParamsStorage`, конфликт автозагрузки устранен.
