# Issue 28 — `ZenMode`: nullable параметры конструктора с `wa()` fallback никогда не используются

**Статус:** 🟢 Закрыта  
**Приоритет:** 🟢 Косметика  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `zenmode/shopPrefillPluginZenMode.class.php`, строки 58–71

## Проблема

Конструктор объявляет четыре nullable параметра с дефолтами через `wa()`:

```php
public function __construct(
    array $zen_settings,
    ?waResponse $response = null,   // ← дефолт: wa()->getResponse()
    ?waView $view = null,            // ← дефолт: wa()->getView()
    ?shopPrefillPluginZenData $zen_data = null,
    ?waRequest $request = null       // ← дефолт: wa()->getRequest()
) {
    $this->response = $response ?? wa()->getResponse();
    // ...
}
```

Единственное место создания `ZenMode` — `shopPrefillPlugin::getZenMode()` — **всегда передаёт все аргументы**. Nullable fallback никогда не активируется, но скрывает зависимости класса и создаёт ложное ощущение, что объект можно создать без них.

## Рекомендация

Сделать зависимости обязательными:

```php
public function __construct(
    array $zen_settings,
    waResponse $response,
    waView $view,
    shopPrefillPluginZenData $zen_data,
    waRequest $request
)
```
