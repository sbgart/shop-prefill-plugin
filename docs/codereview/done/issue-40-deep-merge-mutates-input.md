# Issue 40 — `deepMergeArrays` мутирует входные массивы по ссылке

**Статус:** ✅ Закрыта  
**Приоритет:** 🟡 Средний  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `helpers/shopPrefillPluginHelper.class.php`

## Проблема

Сигнатура метода принимает оба аргумента по ссылке и **мутирует** первый массив:

```php
public static function deepMergeArrays(array &$array1, array &$array2): array
{
    foreach ($array2 as $key => &$value) {
        if (is_array($value) && isset($array1[$key]) && is_array($array1[$key])) {
            self::deepMergeArrays($array1[$key], $value);
        } else {
            $array1[$key] = $value;
        }
    }
    return $array1;
}
```

**Проблемы:**

1. **Побочный эффект** — вызывающий код может не ожидать, что `$array1` изменится
2. **Ненужная ссылка `&$array2`** — второй массив не модифицируется, ссылка бесполезна
3. **Возврат `$array1` по ссылке** — создаёт alias вместо копии

В вызывающем коде (`SessionStorageProvider::preFillCheckoutParams`) это безопасно, потому что `$merged` сразу сохраняется. Но функция опасна для переиспользования.

## Рекомендация

Убрать ссылки, работать с копиями:

```php
public static function deepMergeArrays(array $base, array $override): array
{
    $result = $base;
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
            $result[$key] = self::deepMergeArrays($result[$key], $value);
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
}
```
