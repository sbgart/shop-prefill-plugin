# Логирование (Logging Guide)

Единая система логирования работает и в PHP, и в JS. Основная цель — отделить общие логи от критических ошибок.

## 1. Куда пишутся логи?
- `wa-log/prefill.plugin.log` — Пишутся **только** при включенном режиме отладки Webasyst (`waSystemConfig::isDebug()`). Сюда попадают уровни `INFO` и `DEBUG`.
- `wa-log/prefill.plugin.error.log` — Пишутся **всегда**, даже на "живом" сайте. Сюда попадают уровни `WARNING` и `ERROR`.

## 2. Как логировать в PHP?
Используйте методы класса `shopPrefillPluginLog`:

```php
// Инфо: сохранение настроек, успешное завершение важного действия (пишется только в debug режиме)
shopPrefillPluginLog::info('Storefront settings saved', [
    'storefront' => $storefront_code
]);

// Предупреждение: проблема не ломает чекаут, но требует внимания (пишется всегда)
shopPrefillPluginLog::warning('Contact provider failed', [
    'contact_id' => $id,
    'error' => $e->getMessage()
]);

// Ошибка: критический сбой, не отработал хук или не сохранились данные (пишется всегда)
shopPrefillPluginLog::error('Order creation hook failed', [
    'order_id' => $order_id,
    'exception' => $e->getMessage()
]);
```

**Правила:**
- Всегда передавайте вторым аргументом массив (контекст). В логе он преобразуется в JSON. В массив ОБЯЗАТЕЛЬНО передавайте `$e->getMessage()`, если вы внутри `catch`.
- Текст первого аргумента делайте **статичным** и понятным для поиска (без переменных внутри строки, переменные — в контекст).

## 3. Как логировать в JS (Frontend)?
В новых модулях JS `Logger` пробрасывается через конструктор из `prefill.frontend.js`:

```javascript
// Пишется только если включен режим отладки (info, log, debug)
this.logger.info("User expanded the section");

// Пишется всегда в prefill.plugin.error.log
this.logger.warn("Validation failed for group");
this.logger.error("Failed to load dialog content");
```

**Правила JS:**
JS логгер автоматически дублирует сообщения:
1. Выводит их в консоль браузера (с префиксом `[prefill]`).
2. Отправляет их тихим POST-запросом на бэкенд (`/prefill/logs`), где они записываются так же, как и PHP логи (с префиксом `[Frontend]`).
