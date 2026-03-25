# Issue 45 — Security: debug-контроллеры доступны без авторизации

**Статус:** ⬜ Открыта  
**Приоритет:** 🔴 Критический  
**Сложность фикса:** 🔧 Небольшой  
**Файлы:**
- `actions/frontend/shopPrefillPluginFrontendClearStorage.controller.php`
- `actions/frontend/shopPrefillPluginFrontendResetAndRefill.controller.php`
- `actions/frontend/shopPrefillPluginFrontendResetFirstPrefillDone.controller.php`
- `actions/frontend/shopPrefillPluginFrontendResetSnapshot.controller.php`
- `actions/frontend/shopPrefillPluginFrontendToggleZen.controller.php`
- `actions/frontend/shopPrefillPluginFrontendForcePrefill.controller.php`
- `actions/frontend/shopPrefillPluginFrontendLogs.controller.php`

## Проблема

**7 контроллеров** доступны любому посетителю сайта без проверки прав:

1. `ClearStorage` — очищает checkout-сессию
2. `ResetAndRefill` — полный сброс + повторное предзаполнение
3. `ResetFirstPrefillDone` — сброс сессии checkout
4. `ResetSnapshot` — очистка снапшота
5. `ToggleZen` — переключает Zen Mode **в настройках витрины** (изменяет БД!)
6. `ForcePrefill` — принудительное предзаполнение
7. `Logs` — **запись произвольного текста в серверный лог** (Log Injection!)

**Наиболее опасен `ToggleZen`** — изменяет настройки плагина в БД через `$storefront->saveSettings()`. Любой анонимный посетитель может включить/выключить Zen Mode на витрине.

**`Logs`** — классическая Log Injection уязвимость. Атакующий может заполнить диск, подделать записи лога, или внедрить вредоносный код в лог-файлы.

## Рекомендация

Добавить проверку прав во все debug-контроллеры:

```php
public function execute()
{
    // Только для администраторов в debug-режиме
    if (!waSystemConfig::isDebug() || !wa()->getUser()->isAdmin('shop')) {
        $this->errors = 'Access denied';
        return;
    }
    // ...
}
```

Или вынести в общий базовый класс `shopPrefillPluginFrontendDebugController`.
