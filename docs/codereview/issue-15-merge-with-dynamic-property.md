# Issue 15 — `mergeWith` — незащищённое обращение к приватным свойствам

**Статус:** ⬜ Открыта  
**Приоритет:** 🟢 Косметика  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `fillparams/shopPrefillPluginFillParams.class.php`, строки 638–643

## Проблема

Динамический доступ `$other->$property` к приватным свойствам работает только потому, что оба объекта одного класса. PHP это разрешает, но при опечатке или неправильном имени — `Undefined property` в рантайме без явной ошибки компилятора.

```php
public function mergeWith(shopPrefillPluginFillParams $other, array $properties): void
{
    foreach ($properties as $property) {
        $this->$property = $other->$property; // динамический доступ к свойству
    }
}
```

## Рекомендация

Оставить как есть (работает корректно), но добавить assert или проверку `property_exists`:

```php
foreach ($properties as $property) {
    assert(property_exists($this, $property), "Unknown property: $property");
    $this->$property = $other->$property;
}
```
