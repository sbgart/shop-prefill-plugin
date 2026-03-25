# Issue 48 — `SettingsAction::execute` использует arrow function (PHP 7.4+ requirement)

**Статус:** ✅ Закрыта  
**Приоритет:** 🟢 Низкий  
**Сложность фикса:** ⚡ Минутный  
**Файлы:**
- `actions/shopPrefillPluginSettings.action.php`
- `actions/shopPrefillPluginSettingsStorefront.action.php`

## Проблема

Оба файла используют arrow function (`fn() =>`):

```php
'payment_methods' => array_map(fn($method) => $method['name'], $paymentMethods),
```

В `AGENTS.md` указано: **PHP 7.4** — не использовать синтаксис PHP 8+.

Arrow functions поддерживаются начиная с PHP 7.4, так что формально это не нарушение. Однако стоит зафиксировать в документации, что минимальная версия PHP — **7.4**, а не 7.3 или ниже.

В остальном коде плагина также используются:
- Typed properties (`private array $settings`) — PHP 7.4+
- Nullsafe operator (`??=`) — PHP 7.4+

**Это не баг, а лишь заметка для документации.**

## Рекомендация

Указать в `plugin.php` (или README) минимальную версию PHP: `7.4`. Никаких правок кода не требуется.

## Решение

Добавлен ключ `'php_version_required' => '7.4'` в `lib/config/plugin.php`. Это формально документирует минимальную версию PHP и снимает любые вопросы о совместимости.

