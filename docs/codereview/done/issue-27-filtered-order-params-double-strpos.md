# Issue 27 — `filteredOrderParams`: лишний вызов `strpos`

**Статус:** ✅ Закрыта  
**Приоритет:** 🟢 Косметика  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `fillparams/shopPrefillPluginFillParamsHelper.class.php`, строки 9–11

## Проблема

Двойная проверка с одним и тем же `strpos`:

```php
$pos = strpos($param, $prefix);
if ($pos !== false && $pos === 0) {  // двойная проверка
```

`$pos === 0` уже гарантирует `$pos !== false` (0 не является `false` в PHP). Первая проверка избыточна.

## Рекомендация

```php
if (strpos($param, $prefix) === 0) {
```
