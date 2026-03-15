# Issue 07 — `AssetsManager` — магические пути в строках

**Статус:** ⬜ Открыта  
**Приоритет:** 🟠 Средний  
**Сложность фикса:** 🔧 Небольшой  
**Файл:** `view/shopPrefillPluginAssetsManager.class.php`, строки 63–73

## Проблема

`substr(..., 1)` — неочевидный трюк для обрезки ведущего `/`. Логика пути дублируется для CSS и JS (строки 63 и 71).

```php
$this->getResponse()->addCss(
    substr(wa()->getDataUrl('plugins/' . $this->plugin_id . '/css/', true, 'shop'), 1)
    . $css_variables_filename
);
```

## Рекомендация

Вынести в метод:

```php
private function getPublicDataPath(string $subdir): string
{
    return substr(wa()->getDataUrl('plugins/' . $this->plugin_id . '/' . $subdir . '/', true, 'shop'), 1);
}
```
