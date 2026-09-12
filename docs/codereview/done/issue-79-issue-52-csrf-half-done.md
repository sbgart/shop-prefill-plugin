# Issue 79 — issue-52 закрыта наполовину: CSRF на публичных эндпоинтах так и нет, а в TODO стоит галочка

**Статус:** ✅ Закрыта 11.09.2026
**Приоритет:** 🟠 Средний (и как баг, и как расхождение учёта перед релизом)
**Сложность фикса:** 🔧 Небольшой
**Файлы:** `lib/actions/frontend/shopPrefillPluginFrontendConsent.controller.php`, `...ApplyDelivery.controller.php`, `lib/classes/frontend/shopPrefillPluginCsrfGuard.class.php` (новый), `docs/TODO.md`, `docs/codereview/issue-52-consent-endpoint-log-flood-csrf.md`

> `...FillCheckoutParams.controller.php` из исходного списка файлов к моменту фикса уже был удалён отдельно, см. [issue-62](issue-62-dead-unguarded-fill-checkout-endpoint.md) — CSRF там чинить было нечего.

## Расхождение

- `docs/TODO.md`: `- [x] Публичный эндпоинт consent: флуд лога и отсутствие CSRF`;
- сам `issue-52-…md`: **Статус: ⬜ Открыта**;
- фактически: сделана только «Проблема 1».

Что действительно исправлено: белый список `ACTIONS`, уровень записи понижен до `debug`, сырое значение в лог не пишется, добавлена ротация (`shopPrefillPluginLog::rotateIfNeeded`, 5 МБ, одно поколение) и обрезка контекста. Это закрывает рекомендации 1–3.

Что не сделано — рекомендация 4:

```bash
grep -rn "csrf" lib/actions/frontend/   # → 0 совпадений
```

## Почему это не закрывается ядром

Webasyst проверяет `_csrf` только для бэкенда (`$wa_app->getConfig()->getInfo('csrf')` — у шопа `true`) и для фронтенда **только на secure-маршрутах**:

```php
// wa-system/controller/waDispatch.class.php:378
if (waRequest::param('secure') && waRequest::method() == 'post' && $app_system->getConfig()->getInfo('csrf')) {
```

Маршруты плагина из `lib/config/routing.php` не secure, значит POST на них принимается без токена. Остаётся только `SameSite=Lax` по умолчанию в браузерах.

## Что этим можно сделать

- `POST /prefill/consent` с `action=grant` — проставить посетителю согласие на хранение персональных данных без его действия. Это юридически значимая галочка (152-ФЗ), и опираться на «браузер, наверное, заблокирует» в продаваемом плагине не стоит.
- `action=clear_form` — стереть посетителю сессию оформления заказа (`shop/checkout` + снапшот) в момент, когда он заполняет корзину.
- `action=revoke` / `clear` — удалить гостевой хеш, то есть всю историю предзаполнения.
- `POST /prefill/apply-delivery` с перебором `order_id` — подменить адрес доставки в сессии. Чужие заказы при этом не утекают (`getFillParamsForAuthorized()` сверяет `contact_id`), но состояние формы меняется.

## Рекомендация

1. Добавить проверку токена в публичные POST-эндпоинты, меняющие состояние (`consent`, `apply-delivery`, и `fill-checkout-params`, если он не будет удалён по [issue-62](issue-62-dead-unguarded-fill-checkout-endpoint.md)). Токен уже есть в cookie `_csrf` (его ставит `waAuthUser`), в JS доступен из `document.cookie`, на сервере — `waRequest::post('_csrf') === waRequest::cookie('_csrf')`.
2. Проверять `Sec-Fetch-Site` / `Origin` как второй барьер для браузеров, где он есть.
3. Привести учёт в порядок: снять `[x]` с issue-52 в TODO либо разделить её на две строки (лог — сделано, CSRF — нет). Перед продажей важно, чтобы галочка в TODO означала то же, что статус в файле.

## Как закрыта (11.09.2026)

`fill-checkout-params` к этому моменту уже удалён (issue-62) — правки коснулись только `Consent` и `ApplyDelivery`.

**Важное отличие от рекомендации п.1:** `_csrf`-кука не подошла как основной токен для `Consent`. Она ставится ядром (`waAuthUser::init()`, `wa-system/user/waAuthUser.class.php:105-108`) только уже **авторизованным** контактам — это единственное место в ядре, где кука вообще выставляется. А `grant`/`clear`/`clear_form` вызываются как раз для полностью анонимного гостя (до первого заказа, до какой-либо авторизации) — у него куки ещё нет ни в браузере, ни на сервере. Слепое копирование проверки `hash_equals(cookie, post)` из `shopPrefillPluginFrontendDebugBaseController` сломало бы согласие для настоящих гостей.

Поэтому сделан порядок из рекомендации, но с `_csrf` как резервом, а не основой:

1. `Sec-Fetch-Site` (`same-origin`/`none` — пропуск, иначе блок) — основной барьер, не зависит от кук, есть в современных Chrome/Firefox/Safari 16.4+.
2. Если заголовка нет — `Origin`, затем `Referer`: сверяется хост (без схемы и порта) с `HTTP_HOST` текущего запроса. Сравнение по хосту, а не по конфигу — специально, чтобы одинаково работать на всех витринах стенда (`wa-dev.loc`, `wa-dev.loc/shop-1`, `shop-1.wa-dev.loc`, см. CLAUDE.md).
3. Если нет ни одного заголовка (очень старый браузер либо `Referrer-Policy` их срезала) — резерв: `_csrf`, если кука уже есть (залогиненный/вернувшийся гость с прошлым заказом). Если и её нет — запрос пропускается, как и раньше, без защиты — не регресс, тот же уровень риска, что был до фикса.

Реализация вынесена в отдельный класс `shopPrefillPluginCsrfGuard::isSameOriginRequest()` (`lib/classes/frontend/`), подключена первой строкой в `execute()` обоих контроллеров, ответ при отказе — 403.

**Устранено дублирование с debug-эндпоинтами.** У `shopPrefillPluginFrontendDebugBaseController::isAllowed()` (гейт `ClearStorage`/`ForcePrefill`/`ResetAndRefill`/`Logs` — админ + включённый debug + `_csrf`) была своя копия того же сравнения `hash_equals(cookie, post)`. Сравнение токена вынесено в `shopPrefillPluginCsrfGuard::matchesCsrfToken()` — используется и как резервная ступень в `isSameOriginRequest()`, и напрямую в `DebugBaseController::isAllowed()` вместо дублированных строк. Поведение debug-эндпоинтов не изменилось: там проверка по-прежнему жёсткая, без резерва на «нет куки — пропускаем» (у админа с включённым debug кука гарантированно есть).

Других публичных POST-эндпоинтов, которые меняют состояние и не защищены, не нашлось: `ClearStorage`/`ForcePrefill`/`ResetAndRefill`/`Logs` уже закрыты `DebugBaseController`; `DebugSource`/`RefreshDebug`/`ParamsChoice` только читают и ничего не меняют — CSRF для них не актуален (нечего подделывать).

**Проверено:**

- Оба эндпоинта вызываются только из JS (`ConsentManager.js` через `$.post`, `ParamsChoiceManager.js` через `fetch`), обычных `<form>`-сабмитов на них нет — значит на реальных вызовах браузер всегда шлёт `Sec-Fetch-Site`/`Origin`.
- curl-сценарии на `/prefill/consent/`: без заголовков и без куки — 200 (не регресс); с `Origin`/`Sec-Fetch-Site` своего домена — 200; с поддельным `Origin: https://evil.example` — 403; с `Sec-Fetch-Site: cross-site` — 403. Повторно прогнаны после выноса `matchesCsrfToken()` — поведение не изменилось.
- `/prefill/apply-delivery/`: неавторизованный гость с поддельным `Origin` — 403 (как и раньше, только раньше по другой причине); неавторизованный с легитимным `Origin` — 403 (не регресс, гейт `isAuth()` не тронут).
- Живой сценарий в браузере (реальный `fetch()` со страницы `/order/`, настоящие браузерные заголовки): `consent[grant]` → 200, `apply-delivery` авторизованным контактом → 200; в `wa-log/prefill.plugin.log` — штатные `User granted prefill consent` / `Delivery scenario applied via applyDeliveryAddress`, без отказов.
- После рефакторинга `DebugBaseController::isAllowed()` — живой тест `clear-storage` реальным `fetch()` под Admin: корректный `_csrf` → 200, неверный → 403, отсутствующий → 403 — поведение идентично тому, что было до выноса общего метода.
- После теста сессия `shop/checkout` очищена через debug-панель («Очистить сессию»), чтобы не оставлять изменённое ради проверки состояние.
