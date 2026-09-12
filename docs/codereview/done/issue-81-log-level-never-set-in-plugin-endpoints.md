# Issue 81 — В собственных эндпоинтах плагина уровень лога не инициализируется, debug/info теряются

**Статус:** ✅ Исправлена 22.08.2026 — реализован «второй вариант» из раздела «Как чинить», **проверено в браузере**
**Приоритет:** 🟠 Важно до продажи (отладка у клиента вслепую)
**Сложность фикса:** 🔨 Низкая (одна строка инициализации)

**Файлы:**

- `lib/classes/log/shopPrefillPluginLog.class.php`
- `lib/shopPrefill.plugin.php` — `isActive()`, единственное место вызова `setLevel()`
- `lib/actions/frontend/*.controller.php`, `…ParamsChoice.action.php`

## Симптом

`/prefill/force-prefill` и `/prefill/reset-and-refill` при включённом уровне `debug`
отработали полностью — `general_log` показал реальные запросы к `shop_customer` и
гидратацию заказа — но в `wa-log/prefill.plugin.log` **не появилось ни одной строки**.
Файл не вырос ни на байт.

## Причина

Уровень логирования живёт в статике и выставляется ровно один раз:

```php
// lib/shopPrefill.plugin.php:85, внутри isActive()
shopPrefillPluginLog::setLevel($settings['logging']['level'] ?? 'warning');
```

`isActive()` вызывается только из точек входа хуков (`shopPrefill.plugin.php:475-608`).
Собственные роуты плагина (`lib/config/routing.php` → `frontend/*`) хуками не проходят:
контроллеры дёргают `getEffectiveStorefrontSettings()` и `isDebug()` напрямую.

Значит в этих запросах действует дефолт:

```php
// shopPrefillPluginLog::getLevel()
return self::$configured_level ?? 'warning';
```

`warning` = 2, а `debug` = 4 и `info` = 3, поэтому `isLevelEnabled()` режет оба.
`error()` и `warning()` проходят — потому в логах ошибок эндпоинтов дырки нет,
и проблема не бросалась в глаза.

## Чем плохо

- Настройка «уровень логирования» в админке на эндпоинты плагина не действует вообще.
- Отладка ровно тех путей, ради которых заведены `force-prefill` / `reset-and-refill`,
  невозможна: кнопки работают, лог молчит.
- `ApplyDelivery` пишет `debug`-контекст перед ошибкой — контекст теряется,
  остаётся только сама ошибка.

## Как чинить

Инициализировать уровень там, где он нужен всем путям, а не как побочный эффект `isActive()`.
Вариант: вынести в отдельный `initLogging()` и звать его из `getSettingProvider()` /
конструктора плагина; либо сделать `getLevel()` ленивым — при `null` подтянуть настройки самому.

Второй вариант надёжнее: он закрывает и любые будущие точки входа.
Осторожно с рекурсией — чтение настроек само не должно логировать через `debug()`.

## Как проверить

```bash
POS=$(wc -l < wa-log/prefill.plugin.log)
# в браузере, залогинившись админом на витрине:
#   await fetch('/prefill/force-prefill', {method:'POST'})
tail -n +$((POS+1)) wa-log/prefill.plugin.log
```

Сейчас — пусто. После фикса при уровне `debug` должны появиться строки загрузки источника.

## Как исправлено — 22.08.2026

Реализован «второй вариант»: `shopPrefillPluginLog::getLevel()` теперь ленивый — если уровень
ещё не установлен явным `setLevel()` (единственный вызов которого по-прежнему живёт в
`isActive()`), она сама читает `shopPrefillPlugin::getInstance()->getSettingProvider()->getSettings()`
при первом обращении. Работает для любой точки входа, а не только для хуков — включая будущие.
Ошибка чтения настроек (например, `getInstance()` до полной инициализации Webasyst) гасится
в `'warning'`, а не роняет запрос. Рекурсия исключена явным флагом `$loading_level`: цепочка
чтения настроек (`SettingProvider` → `SettingGroup`/`SettingField` → `SettingsModel`) сама
никуда не логирует, флаг — страховка на случай, если это перестанет быть так.

Проверено в браузере ровно рецептом из раздела выше: `POST /prefill/force-prefill/` от имени
`user:1` — до фикса ни одной новой строки, после фикса появились `Loading fill params for
authorized user`, `Fill params loaded from last order` и полная трасса `SectionChecker` по
всем шести секциям.

Файл: `lib/classes/log/shopPrefillPluginLog.class.php` (`getLevel()`, новый приватный
`loadLevelFromSettings()`). `isActive()` не тронута — её явный `setLevel()` остаётся,
просто больше не единственный источник.
