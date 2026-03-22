# Issue 16 — Дублирующий класс в `shopPrefillPluginFillParamsStorage.class.php`

**Статус:** ⬜ Открыта  
**Приоритет:** 🔴 Критический  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `fillparams/shopPrefillPluginFillParamsStorage.class.php`

## Проблема

Файл `shopPrefillPluginFillParamsStorage.class.php` содержит `class shopPrefillPluginGuestHashStorage` — то же имя класса, что и в `shopPrefillPluginGuestHashStorage.class.php`. Два разных файла определяют один и тот же класс.

Webasyst-автолоадер загружает первый найденный файл — второй тихо игнорируется. Непонятно, какая из двух версий класса реально используется в рантайме.

Скорее всего, это артефакт рефакторинга: файл был переименован, но класс внутри — нет.

## Рекомендация

Удалить `shopPrefillPluginFillParamsStorage.class.php` — `shopPrefillPluginGuestHashStorage` уже живёт в правильном файле.
