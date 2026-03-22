# Issue 24 — Мёртвые методы `generateCssVariablesFile` и `generateJSInitializerFile` в плагине

**Статус:** ✅ Исправлена  
**Приоритет:** 🟢 Косметика  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `shopPrefill.plugin.php`, строки 351–362

## Проблема

Два приватных метода — только прокси в `AssetsManager`, нигде не вызываются:

```php
private function generateCssVariablesFile(array $css_variables): string
{
    return $this->getAssetsManager()->generateCssVariablesFile($css_variables);
}

private function generateJSInitializerFile(array $params): string
{
    return $this->getAssetsManager()->generateJSInitializerFile($params);
}
```

Инициализация assets проходит через `getAssetsManager()->init()` или `frontendAssetsInit()` — эти методы не используются.

## Рекомендация

Удалить оба метода.
