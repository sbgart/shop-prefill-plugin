# Issue 71 — Мёртвый код уезжает в релизный архив, часть его — «настройки, которые ничего не делают»

**Статус:** ⬜ Открыта
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
