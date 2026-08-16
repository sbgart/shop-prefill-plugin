# Issue 79 — issue-52 закрыта наполовину: CSRF на публичных эндпоинтах так и нет, а в TODO стоит галочка

**Статус:** ⬜ Открыта
**Приоритет:** 🟠 Средний (и как баг, и как расхождение учёта перед релизом)
**Сложность фикса:** 🔧 Небольшой
**Файлы:** `lib/actions/frontend/shopPrefillPluginFrontendConsent.controller.php`, `...ApplyDelivery.controller.php`, `...FillCheckoutParams.controller.php`, `docs/TODO.md`, `docs/codereview/issue-52-consent-endpoint-log-flood-csrf.md`

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
