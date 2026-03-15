# Issue 12 — `OrderProvider` — геттеры для полей-инъекций бессмысленны

**Статус:** ⬜ Открыта  
**Приоритет:** 🟢 Косметика  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `orders/shopPrefillPluginOrderProvider.class.php`, строки 16–24

## Проблема

Оба поля инициализируются в конструкторе и никогда не могут быть `null` после этого. Возвращаемый тип `?Model` вводит в заблуждение, а геттеры не несут смысловой нагрузки.

```php
private function getOrderModel(): ?shopOrderModel { return $this->order_model; }
private function getOrderParamsModel(): ?shopOrderParamsModel { return $this->order_params_model; }
```

## Рекомендация

Обращаться напрямую к `$this->order_model`, убрать nullable типы.
