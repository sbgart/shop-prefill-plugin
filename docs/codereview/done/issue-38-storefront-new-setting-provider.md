# Issue 38 — `Storefront`: новый `StorefrontSettingProvider` на каждый экземпляр

**Статус:** ✅ Решена  
**Приоритет:** 🟡 Средний  
**Сложность фикса:** 🔧 Небольшой  
**Файл:** `storefronts/shopPrefillPluginStorefront.class.php`, конструктор

## Проблема

Каждый объект `shopPrefillPluginStorefront` создаёт свой экземпляр `shopPrefillPluginStorefrontSettingProvider`:

```php
public function __construct(string $domain, string $url, array $route = [])
{
    // ...
    $this->setting_provider = new shopPrefillPluginStorefrontSettingProvider();
}
```

В `StorefrontSettingProvider.__construct()` вызывается `shopPrefillPlugin::getConfig('storefront.settings')`, который читает PHP-файл с диска.

При 5 витринах → 5 экземпляров провайдера → 5 чтений файла конфигурации и 5 вызовов `buildStructure()`.

## Рекомендация

Использовать DI — передавать единственный провайдер через конструктор:

```php
public function __construct(
    string $domain, 
    string $url, 
    shopPrefillPluginStorefrontSettingProvider $setting_provider,
    array $route = []
) {
    $this->setting_provider = $setting_provider;
}
```

Или применить Flyweight/Singleton для `StorefrontSettingProvider`.
