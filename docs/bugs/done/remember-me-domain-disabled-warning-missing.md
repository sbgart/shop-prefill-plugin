# `rememberme=false` на домене: ни токен, ни предупреждение в логе не появляются (I-05)

**Статус:** ❌ не баг (false positive), закрыто 06.09.2026. См. [«Разбор»](#разбор-06092026) в конце файла.
Обнаружено 05.09.2026 при подготовке I-05 (прогон R, ФО-09).

## Ожидание (по коду)

`shopPrefillPluginFrontendHooks::handleRememberMeCookie()`:

```php
$pending_auth = $this->session_storage->consumePendingAuth();
if (! $this->user_provider->isAuth()) {
    return;
}
if (! $this->user_provider->isDomainRememberMeEnabled()) {
    if ($pending_auth) {
        shopPrefillPluginLog::warning(
            'Cannot keep customer signed in: "remember me" is disabled for this domain'
        );
    }
    return;
}
```

Если у гостя выставлена метка `pending_auth` (заказ в режиме `existing_contact`/`confirm_contact`,
`remember_me.on_order=true`) и после заказа `postConfirm()` реально авторизовал покупателя
(`isAuth()=true`), а на домене витрины выключено «Запомнить меня» (`rememberme=false` в
`wa-config/auth.php`) — должна появиться строка `[WARNING] Cannot keep customer signed in...`.

## Воспроизведение

1. Настройки плагина → сторона `wa-dev.loc/*` → «Использовать индивидуальные настройки» = вкл,
   `remember_me.on_order` = вкл (это единственный способ получить `on_order=true` для реальной
   витрины — глобальная `*` настройка на этом стенде хранит `on_order=false`, см.
   [storefront-settings-orphaned-by-url-promotion.md](storefront-settings-orphaned-by-url-promotion.md)).
2. Настройки чекаута → «Покупатель» → «Обновление профилей...» = `existing_contact`.
3. Гость (чистая cookie-банка, curl) добавляет товар в корзину и оформляет заказ на **новый**,
   ранее не встречавшийся email. `existing_contact` не требует подтверждения для нового адреса —
   заказ создаётся, `postConfirm()` авторизует покупателя автоматически.
4. **С `rememberme=true` на домене** (baseline): следующая загрузка страницы той же гостевой
   сессией пишет `[INFO] Auth token issued after order confirmation` — ожидаемо, воспроизведено
   дважды (заказы #118, #119).
5. **С `rememberme=false`** (`wa-config/auth.php`, PHP-FPM перезапущен, `wa-cache` очищен): тот же
   сценарий с новым email (заказы #120, #121) — покупатель **авторизуется** (`user:NN` появляется
   в логе на той же следующей загрузке, `postConfirm()`/`wa()->getAuth()->auth()` не зависят от
   `rememberme` домена), но **ни** `[INFO] Auth token issued...`, **ни** `[WARNING] Cannot keep
   customer signed in...` в логе не появляется — полная тишина в этой части хука.

Воспроизведено дважды подряд (#120 сразу после правки конфига, #121 после дополнительного
перезапуска PHP-FPM) — не разовая случайность.

## Проверено и исключено

- Значение `rememberme` в файле `wa-config/auth.php` на момент теста — подтверждено `false` (прямое
  чтение `wa()->getConfig()->getAuth()` через отдельный CLI-скрипт, минуя весь HTTP-стек).
- `waConfigCache` (мемкэш конфигов) неактивен — `config_cache_enable` не выставлен в
  `wa-config/config.php`, `includeFile()` в этом случае просто делает `include()` без кэша.
- `waRuntimeCache`/`waDomainAuthConfig::$static_cache` — статические свойства класса, живут только
  в рамках одного PHP-FPM запроса; полный перезапуск PHP-FPM (`servbayctl restart php 7.4`) сделан
  между заказом #119 (сработало) и #120 (не сработало) — не помогло.
- `shop_prefill_settings`-строки `on_order`/`active` для витрины проверены в БД до и после — не
  менялись, совпадают с рабочим прогоном #119.
- `order_without_auth` для витрины (`wa-config/apps/shop/checkout2.php:1174`) — не менялся, всё ещё
  `existing_contact`, подтверждено `grep` до и после.

## Не разбиралось

- Не найдено, какая именно строка кода/условие приводит к тишине вместо `[WARNING]`
  (`isDomainRememberMeEnabled()` не проверялась напрямую *внутри* обработки того же самого HTTP-
  запроса — только косвенно, через CLI-скрипт и через отдельный банер в бэкенд-настройках плагина,
  который в этот раз тоже не перепроверялся заново после правки `auth.php`).
- Не исключена гонка/особенность самого `consumePendingAuth()` — не проверялось напрямую, стоит ли
  метка в сессии между созданием заказа и следующей загрузкой при выключенном `rememberme` (могла
  бы объяснить тишину и без связи с доменной настройкой, но тогда неясно, почему поведение чётко
  коррелирует именно с правкой `rememberme`).

## Разбор (06.09.2026)

Кода-бага нет — `handleRememberMeCookie()`, `consumePendingAuth()` и `isDomainRememberMeEnabled()`
отрабатывают ровно так, как задумано, и `[WARNING]` реально писался в обоих прогонах (#120, #121).
Причина «тишины» — при проверке смотрели не в тот файл лога.

`shopPrefillPluginLog` пишет разные уровни в разные файлы
(`lib/classes/log/shopPrefillPluginLog.class.php:5-6`, см. также
[LOGGING.md](../guides/LOGGING.md)):

- `info()`/`debug()` → `wa-log/prefill.plugin.log` (только при включённом debug-режиме Webasyst);
- `warning()`/`error()` → `wa-log/prefill.plugin.error.log` (всегда).

В бейзлайне (`rememberme=true`, заказ #119) смотрели `prefill.plugin.log` и видели
`[INFO] Auth token issued...` — потому что INFO пишется именно туда. Это создало ложное
впечатление, что весь механизм логируется в одно место. Когда включили `rememberme=false`,
ожидаемая строка стала `[WARNING]`, а она физически пишется в `prefill.plugin.error.log`,
который не проверялся.

Прямое доказательство на этом же стенде:

```console
$ grep -n "Cannot keep customer signed in" wa-log/prefill.plugin.error.log
3:[WARNING] Cannot keep customer signed in: "remember me" is disabled for this domain
6:[WARNING] Cannot keep customer signed in: "remember me" is disabled for this domain
```

Обе строки датированы теми же секундами, что и заказы #120/#121 (`user:61`/`user:62`).

Встроенный вьюер логов плагина в бэкенде (`shopPrefillPluginLogReader::readMerged()`) сливает
оба файла — в UI проблема не проявилась бы вообще. Она возникла только из-за ручной проверки
одного файла напрямую (grep/CLI-скрипт), в обход `docs/guides/LOGGING.md`.

**Вывод:** баг закрыт как false positive. `docs/tests/TESTS.md`, строка I-05 обновлена на ✅.
На будущее — при проверке логирования сверяться с `LOGGING.md` или смотреть оба файла разом:
`cat wa-log/prefill.plugin.log wa-log/prefill.plugin.error.log | sort`.
