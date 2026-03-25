# Issue 46 — Дублирование кода сбора debug-информации в `Debug` и `RefreshDebugController`

**Статус:** ✅ Закрыта  
**Приоритет:** 🟡 Средний  
**Сложность фикса:** 🔧 Средний  
**Файлы:**
- `debug/shopPrefillPluginDebug.class.php` (метод `renderDebugStack`, строки 200-260)
- `actions/frontend/shopPrefillPluginFrontendRefreshDebug.controller.php` (строки 20-72)

## Проблема

Блок с подготовкой `fill_params_meta` (авторизация, guest_hash, количество заказов, источник данных) **скопирован почти дословно** в двух местах:

### В `shopPrefillPluginDebug::renderDebugStack()`:
```php
$fill_params_meta = [
    'user_authorized' => false,
    'user_id' => null,
    // ...
];
// ... ~60 строк логики сбора данных
```

### В `shopPrefillPluginFrontendRefreshDebugController::execute()`:
```php
$fill_params_meta = [
    'user_authorized' => false,
    'user_id' => null,
    // ...
];
// ... ~60 строк точно такой же логики
```

Это нарушение DRY. Любое изменение в логике сбора debug-данных требует правки в двух местах.

## Рекомендация

Выделить общую логику в отдельный метод `Debug`:

```php
public static function collectDebugData(shopPrefillPlugin $plugin): array
{
    return [
        'fill_params_data' => ...,
        'fill_params_meta' => ...,
        'current_storage' => ...,
        'snapshot_storage' => ...,
    ];
}
```

Вызывать из обоих мест.
