# Issue 08 — `getStorefrontSettings()` — экземплярный метод делегирует в статик

**Статус:** ✅ Закрыта  
**Приоритет:** 🟢 Косметика  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `shopPrefill.plugin.php`, строки 130–133

## Проблема

`self::getStorefrontProvider()` вызывает instance-метод через псевдостатику. В PHP 7.4 это работает, но запутывает — по записи кажется статиком.

```php
public function getStorefrontSettings(): array
{
    return self::$storefront_settings ??= self::getStorefrontProvider()->getCurrentStorefront()->getSettings();
    //                                          ^^^
    // getStorefrontProvider() — instance метод, вызывается через self
}
```

## Рекомендация

```php
return self::$storefront_settings ??= $this->getStorefrontProvider()->getCurrentStorefront()->getSettings();
```
