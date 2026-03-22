# Issue 23 — `isSameDeliveryOption`: одностороннее сравнение массивов

**Статус:** ✅ Исправлена  
**Приоритет:** 🟡 Средний  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `fillparams/shopPrefillPluginFillParams.class.php`, метод `isSameDeliveryOption`

## Проблема

Сравнение массивов проверяет только направление `this → other`, но не `other → this`:

```php
foreach ($this_value as $key => $val) {
    if (!isset($other_value[$key]) || $other_value[$key] != $val) {
        return false;
    }
}
// Не проверяет: есть ли у other лишние ключи, которых нет у this
```

То же касается `shipping_address_custom`:

```php
foreach ($this_custom as $key => $val) {
    if (!isset($other_custom[$key]) || $other_custom[$key] != $val) {
        return false;
    }
}
```

**Пример:** `$this = ['a' => '1']`, `$other = ['a' => '1', 'b' => '2']` → метод вернёт `true`, хотя объекты разные.

## Рекомендация

Заменить ручной цикл на `==` для массивов (PHP сравнивает массивы по значениям) или явно проверять в обе стороны:

```php
if (is_array($this_value)) {
    if ($this_value != $other_value) {
        return false;
    }
}
```
