# Issue 14 — `isUserAuthenticated()` в `SessionStorageProvider` — дублирует `UserProvider`

**Статус:** ⬜ Открыта  
**Приоритет:** 🟡 Низкий  
**Сложность фикса:** 🔧 Небольшой  
**Файл:** `sessionstorage/shopPrefillPluginSessionStorageProvider.class.php`, строки 210–220

## Проблема

`shopPrefillPluginSessionStorageProvider` не имеет инъекции `UserProvider`, поэтому сделан отдельный метод с прямым обращением к `wa()`. Это нарушает DI: провайдер напрямую обращается к глобальному состоянию.

```php
private function isUserAuthenticated(): bool
{
    try {
        return wa()->getUser()->isAuth();
    } catch (waException $e) { ... }
}
```

## Рекомендация

Инжектировать `shopPrefillPluginUserProvider` в `SessionStorageProvider`.
