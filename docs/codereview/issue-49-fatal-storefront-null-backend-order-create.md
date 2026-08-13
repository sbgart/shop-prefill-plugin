# Issue 49 — БЛОКЕР: фатальная ошибка при создании заказа вне фронтенда

**Статус:** ✅ Закрыта
**Приоритет:** 🔴 Блокер релиза
**Сложность фикса:** 🔧 Небольшой
**Файл:** `lib/shopPrefill.plugin.php:163-181` (`getStorefrontSettings`), `lib/classes/storefronts/shopPrefillPluginStorefrontProvider.class.php` (`getCurrentStorefront`)

## Проблема

`getCurrentStorefront()` объявлен как `?shopPrefillPluginStorefront` и **реально возвращает `null`**, когда роутинг витрины не задиспатчен:

```php
$storefront_code = base64_encode($domain . '/' . $url);  // $url = null вне фронтенда
return $storefronts->getByCode($storefront_code);        // → null
```

`getStorefrontSettings()` использует результат без проверки:

```php
$storefront = $this->getStorefrontProvider()->getCurrentStorefront();
$settings   = $storefront->getSettings();   // ← Error: Call to a member function getSettings() on null
```

Хук `order_action.create` срабатывает **не только на фронтенде**: бэкенд («Создать заказ» в админке), API, CLI, импорт, сторонние плагины. В этих контекстах `wa()->getRouting()->getRoute('url')` === `null`, код витрины не совпадает ни с одной, и `orderActionCreate` падает.

### Воспроизведение (проверено)

```php
$_SERVER['HTTP_HOST'] = 'wa-dev.loc';          // домен реальной витрины
$wa = waSystem::getInstance(null, new SystemConfig('backend'));
waSystem::getInstance('shop', null, true);
$p = wa('shop')->getPlugin('prefill');
$p->orderActionCreate(['order_id' => 1]);
// THROWN: Error: Call to a member function getSettings() on null
//   at lib/shopPrefill.plugin.php:167
```

### Почему это блокер

`waEvent::runPlugins()` оборачивает вызов хука в `catch (Exception $e)`. **`Error` (в т.ч. `TypeError` и «call on null») этим блоком не перехватывается** — исключение уходит наверх и роняет весь запрос создания заказа 500-й ошибкой. То есть при активном плагине магазин теряет возможность создавать заказы из админки/API.

## Рекомендация

1. `getStorefrontSettings()` — фоллбэк на глобальную витрину `'*'`, если текущая не определена:

```php
$storefront = $this->getStorefrontProvider()->getCurrentStorefront()
    ?: $this->getStorefrontProvider()->getStorefront('*');
```

2. `getStorefront('*')` тоже может вернуть `null` (см. [issue-57](issue-57-minor-robustness-findings.md)) — гарантировать создание объекта витрины по коду, а не поиск в коллекции.
3. Проверить остальные точки вызова `getCurrentStorefront()` (`resolveStorefrontCssUrl`, `FrontendToggleZen`) на ту же ошибку.
4. Добавить в ручные тесты релиза: создание заказа в бэкенде и через API при включённом плагине.

## Как исправлено

Вместо точечной заплатки резолв витрины сведён в одну точку, а nullable-методы получили честные имена:

1. `shopPrefillPluginStorefrontProvider`: `getCurrentStorefront()` → `findCurrentStorefront()` с ранним выходом при `getRoute('url') === null`; добавлены `hasCurrentStorefront()`, `findStorefront($code)` (строгий поиск) и `getGlobalStorefront()`, который **конструирует** витрину `'*'`, а не ищет её в коллекции. Старые `getStorefront()` / `getCurrentStorefront()` удалены.
2. `shopPrefillPlugin::getEffectiveStorefront()` — витрина текущего запроса либо глобальная `'*'`, если текущей нет или она неактивна. `getStorefrontSettings()` → `getEffectiveStorefrontSettings()`, `clearStorefrontSettingsCache()` → `clearEffectiveStorefrontCache()`.
3. `orderActionCreate()` — проверка `isStorefrontRequest()` **до** `isActive()` и до сборки `getOrderHooks()` (фатал возникал именно на аргументе конструктора, поэтому guard внутри `OrderHooks` не помог бы). Побочный эффект: в бэкенде больше не создаётся гостевой хеш из cookie администратора и не пишется в заказ покупателя.
4. Админские экшены (`SettingsStorefront`, `SettingsGetCss`, `saveSettings`) переведены на `findStorefront()` с явной ошибкой `error.storefront_not_found` — закрывает [issue-57 п.1](issue-57-minor-robustness-findings.md).

Проверено: в контексте `SystemConfig('backend')` `orderActionCreate(['order_id' => 1])` завершается тихо, `findCurrentStorefront()` → `null`, эффективная витрина → `'*'`.
