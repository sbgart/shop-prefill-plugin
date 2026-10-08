# Логирование (Logging Guide)

Единая система логирования работает и в PHP, и в JS. Основная цель — отделить общие логи от критических ошибок.

## 1. Куда пишутся логи?
- `wa-log/prefill.plugin.log` — уровни `INFO` и `DEBUG`.
- `wa-log/prefill.plugin.error.log` — уровни `WARNING` и `ERROR`.

Что именно пишется, определяет настройка «Уровень логирования» (вкладка «Отладка», общая для всех витрин): `off`, `error`, `warning` (по умолчанию), `info`, `debug`. Режим отладки Webasyst на запись логов не влияет — на живом сайте при уровне `warning` пишутся предупреждения и ошибки, а `INFO`/`DEBUG` — только после повышения уровня.

## 2. Как логировать в PHP?
Используйте методы класса `shopPrefillPluginLog`:

```php
// Инфо: сохранение настроек, успешное завершение важного действия (пишется при уровне `info` и выше)
shopPrefillPluginLog::info('Storefront settings saved', [
    'storefront' => $storefront_code
]);

// Предупреждение: проблема не ломает чекаут, но требует внимания (пишется при уровне `warning` и выше)
shopPrefillPluginLog::warning('Contact provider failed', [
    'contact_id' => $id,
    'error' => $e->getMessage()
]);

// Ошибка: критический сбой, не отработал хук или не сохранились данные (пишется при любом уровне, кроме `off`)
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
// Пишется только тому, кому видна панель отладки (debug_panel + полный доступ к магазину)
this.logger.info("User expanded the section");

// Пишется в prefill.plugin.error.log
this.logger.warn("Validation failed for group");
this.logger.error("Failed to load dialog content");
```

**Правила JS:**
JS логгер автоматически дублирует сообщения:
1. Выводит их в консоль браузера (с префиксом `[prefill]`).
2. Отправляет их тихим POST-запросом на бэкенд (`/prefill/logs`), где они записываются так же, как и PHP логи (с префиксом `[Frontend]`).
