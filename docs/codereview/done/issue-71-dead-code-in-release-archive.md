# Issue 71 — Мёртвый код уезжает в релизный архив, часть его — «настройки, которые ничего не делают»

**Статус:** ✅ Закрыта 24.08.2026 — кроме `shopPrefillPluginIntegrations`, оставлен намеренно (см. «Выполнено»)
**Приоритет:** 🟢 Низкий (гигиена), но один пункт — функциональный
**Сложность фикса:** 🔧 Небольшой
**Файлы:** см. список

## Проверено grep'ом по `lib/`, `js/`, `templates/` — ноль вызовов, кроме самого объявления

| Что | Файл | Объём |
|---|---|---|
| `shopPrefillPluginIntegrations` (cityselect, dp, setCookies) | `lib/classes/integrations/…` | 82 строки |
| `ShortShippingInfoSection.html` | `templates/checkout/` | шаблон целиком |
| `ViewProvider::getFormattedPrice()`, `getFormattedMessage()` | `lib/classes/view/…` | 2 метода |
| `ZenMode::getGroupIcon()` (помечен `@deprecated`) | `lib/classes/zenmode/…` | 1 метод |
| `FillParams::mergePaymentParams/mergeAuthParams/mergeContactParams` + `mergeWith` + поля `$payment_params`, `$auth_params`, `$contact_params` | `lib/classes/fillparams/…` | 4 метода + 3 поля |
| `FillParams::$active`, `isActive()`, `setActive()` | `lib/classes/fillparams/…` | поле никогда не пишется |
| `FillParams::getAuthField()`, `setAuthField()` | `lib/classes/fillparams/…` | 2 метода |
| `LocationProvider::getCountries()`, `getRegions()` | `lib/classes/location/…` | 2 метода |
| `GuestHashStorage::hasGuestHash()` | `lib/classes/fillparams/…` | 1 метод |

## Функциональный пункт: контактные поля `FillParams` никогда не заполняются

Блок «Главные поля контактов» (`$title`, `$firstname`, `$middlename`, `$lastname`, `$jobtitle`, `$company`, `$email`, `$phone`) имеет геттеры и сеттеры, но **ни один сеттер не вызывается** — данные контакта кладутся только в `$auth_data`. При этом на эти поля опирается `hasDataForSection()`:

```php
case 'auth':
    return ! empty($this->email) || ! empty($this->phone) || ! empty($this->auth_data) || ! empty($this->firstname);
case 'details':
    return ! empty($this->street) || ! empty($this->zip) || ! empty($this->lastname) || ! empty($this->company)
        || ! empty($this->shipping_address_custom);
```

Три из четырёх условий для `auth` и два из пяти для `details` — всегда `false`. Работает всё это благодаря оставшимся условиям (`auth_data`, `street`/`zip`). Блокирующего эффекта нет: `hasDataForSection()` вызывается только в debug-панели (`FrontendHooks::logDebugBeforePrefill`), но диагностика показывает неправду, и любой, кто будет разбирать этот класс дальше, наступит на грабли.

## Побочный эффект для API

`FillParams::toArray()` — это `get_object_vars($this)` из контекста класса, то есть в JSON диалога «Мои варианты» уезжают и всегда-null контактные поля, и служебные списки `region_params` / `auth_params` / `contact_params` / `payment_params` / `shipping_params`. Формально это уже отмечено в [issue-33](done/issue-33-to-array-leaks-private-props.md) как «вопрос чистоты контракта» и закрыто; при чистке мёртвых полей проблема уходит сама.

## Рекомендация

1. Удалить перечисленное. Настройки `prefill.integration.cityselect` / `.dp` в `storefront.settings.php` — либо удалить вместе с классом, либо оставить с явной пометкой «зарезервировано» (сейчас они выглядят как рабочие тумблеры, которые ни на что не влияют).
2. Контактные поля: либо заполнять их в `fillAuthDataFromOrder()` из контакта, либо убрать и переписать `hasDataForSection()` на `auth_data`/`shipping_address_custom`. Второе честнее — `auth_data` уже содержит эти же поля.
3. После чистки прогнать `php wa.php compress shop/plugins/prefill -style false` — он валидирует синтаксис и покажет, не осталось ли битых ссылок.

## Выполнено

**Удалено** (grep-подтверждение перед удалением — см. историю; после удаления заново прогнаны grep + тесты + `compress`):

- `templates/checkout/ShortShippingInfoSection.html` — файл удалён целиком, подключений не было.
- `shopPrefillPluginViewProvider::getFormattedPrice()`, `::getFormattedMessage()` — удалены.
- `shopPrefillPluginZenMode::getGroupIcon()` (`@deprecated`) — удалён, замена `getPluginGroupIcon()` жива и используется.
- `FillParams::mergePaymentParams/mergeAuthParams/mergeContactParams/mergeWith` + поля `$payment_params`/`$auth_params`/`$contact_params` — удалены. `$shipping_params` не тронут — используется в `isSameDeliveryOption()`.
- `FillParams::$active`, `isActive()`, `setActive()` — удалены. (Другие классы плагина, `isActive()` на своих собственных полях — `shopPrefillPlugin`, `ZenMode`, `Storefront` — не трогали, это другие сущности с тем же именем метода.)
- `FillParams::getAuthField()`, `setAuthField()` — удалены, `auth_data` теперь читается/пишется только целиком через `getAuthData()`/`setAuthData()`.
- `shopPrefillPluginLocationProvider::getCountries()`, `::getRegions()` — удалены.
- `GuestHashStorage::hasGuestHash()` — пункт устарел ещё до этой правки: класс уже был переименован в `GuestTokenStorage` при закрытии issue-63, метод — в `hasToken()` (используется). Удалять было нечего.

**Не тронуто сознательно:** `shopPrefillPluginIntegrations` (`cityselect()`, `dp()`, `setCookies()`) и настройки `prefill.integration.cityselect`/`.dp` в `storefront.settings.php` — по решению автора плагина класс скоро будет подключён к реальной логике, удаление отменили бы эту работу. Тумблер `cityselect` в UI (`templates/actions/settings/blocks/tabs/Prefill.html`) пока остаётся «переключателем в никуда» — это сознательный компромисс, не забытый пункт.

**Функциональный пункт (контактные поля):** выбран второй вариант рекомендации — поля `$title/$firstname/$middlename/$lastname/$jobtitle/$company/$email/$phone` вместе с геттерами/сеттерами удалены как никогда не заполнявшиеся (`fillAuthDataFromOrder()` кладёт данные контакта только в `$auth_data`, отдельные сеттеры не вызывались нигде). `hasDataForSection()` переписан: кейс `auth` теперь проверяет только `!empty($this->auth_data)`, кейс `details` лишился ссылок на удалённые `$lastname`/`$company` и опирается на `$street`/`$zip`/`$shipping_address_custom`.

Попутно уточнение к тексту issue: `hasDataForSection()` на момент правки не вызывался вообще ниоткуда (не только из debug-панели, как здесь написано) — `FrontendHooks::logDebugBeforePrefill` был отрефакторен и в коде отсутствует ещё с закрытия issue-50. Собственный докблок метода честно называет его намеренным заделом под issue-84 §2 — поэтому сам метод не удалялся, только его логика приведена в соответствие с реально живыми полями.

**Побочный эффект для API** (см. «Побочный эффект» выше) закрылся сам собой: `toArray()`/`get_object_vars($this)` больше не отдаёт всегда-`null` контактные поля и мёртвые списки `auth_params`/`contact_params`/`payment_params`. Проверены все 4 потребителя `toArray()` (`FillParamsCollection`, `shopPrefillPluginDebug`, `FrontendForcePrefill`, `FrontendParamsChoice`) и шаблон `templates/debug/DebugFillParams.html` — все дампят массив целиком через `@print_r`/JSON, ни один не обращается к конкретным удалённым ключам.

**Валидация:**

- `php -l` по всем изменённым файлам — чисто.
- `for t in tests/*Test.php; do php "$t"; done` — все тесты зелёные (166 проверок в `SectionCheckerOwnershipVsDataTest` и т.д., без единого провала).
- `php wa.php compress shop/plugins/prefill -style false` — архив собран без ошибок, 131 файл; `ShortShippingInfoSection.html` в списке больше нет.
- grep по `lib/`, `js/`, `templates/`, `tests/` на все удалённые символы — ноль совпадений, кроме заведомо других сущностей с совпадающими именами (`isActive()` на других классах, `getEmail()/getPhone()/getCompany()` на `shopPrefillCheckoutState` — не `FillParams`).
