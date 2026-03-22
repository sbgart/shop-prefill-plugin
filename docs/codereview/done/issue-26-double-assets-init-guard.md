# Issue 26 — Двойная защита от повторной инициализации assets

**Статус:** ✅ Исправлена  
**Приоритет:** 🟢 Косметика  
**Сложность фикса:** ⚡ Минутный  
**Файлы:** `shopPrefill.plugin.php`, `view/shopPrefillPluginAssetsManager.class.php`

## Проблема

Защита от повторного вызова `init()` продублирована в двух местах:

```php
// shopPrefill.plugin.php — статический флаг
private static bool $frontend_assets_inited = false;

public function frontendAssetsInit(...): void
{
    if (!self::$frontend_assets_inited) {
        $this->getAssetsManager()->init(...);
        self::$frontend_assets_inited = true;
    }
}

// shopPrefillPluginAssetsManager — флаг экземпляра
private bool $assets_initialized = false;

public function init(...): void
{
    if ($this->assets_initialized) { return; }
    // ...
    $this->assets_initialized = true;
}
```

Ответственность за идемпотентность — это зона `AssetsManager`. Дублирование флага в плагине добавляет неочевидное состояние без пользы.

## Рекомендация

Удалить `$frontend_assets_inited` и обёртку в `shopPrefillPlugin::frontendAssetsInit`. Полагаться только на проверку в `AssetsManager::init()`.
