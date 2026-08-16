# Issue 62 — Мёртвый публичный эндпоинт `fillCheckoutParams` без проверки доступа

**Статус:** ⬜ Открыта
**Приоритет:** 🟢 Низкий (уборка кода + сужение поверхности атаки)
**Сложность фикса:** 🔧 Тривиальный (удалить файл)
**Файл:** `lib/actions/frontend/shopPrefillPluginFrontendFillCheckoutParams.controller.php`

## Проблема

Контроллер не вызывается ниоткуда: по `js/`, `templates/` и `lib/` нет ни одного упоминания `fillCheckoutParams` / `FillCheckoutParams` (кроме `CLAUDE.md` и документов ревью).

При этом он остаётся доступным публично и делает ту же работу, что и `shopPrefillPluginFrontendForcePrefillController`, но **без его защиты**:

```php
// ForcePrefill — закрыт
if (! shopPrefillPlugin::getInstance()->isDebug() || ! wa()->getUser()->isAdmin('shop')) {
    $this->errors = 'Access denied';
    return;
}

// FillCheckoutParams — открыт всем, проверяется только активность витрины
$fill_params_id = waRequest::post('id', null);
$fill_params = $instance->getFillParamsProvider()->getFillParams($fill_params_id);
$instance->getSessionStorageProvider()->preFillCheckoutParams($fill_params);
```

Дополнительно: `waRequest::post('id', null)` идёт без типа в `getFillParams(?int $fill_params_id)`. Массив в `id` даёт `TypeError`, а он наследник `Error`, а не `Exception` — блок `catch (Exception $e)` его не поймает, ответом будет 500.

## Последствия

- Неаутентифицированный вызов пишет в сессию и (при уровне `info`) в лог — та же поверхность, что разбиралась в [issue-52](issue-52-consent-endpoint-log-flood-csrf.md);
- чужой заказ через `id` не утекает: для авторизованного `getFillParamsForAuthorized()` сверяет `contact_id` заказа, для гостя параметр вовсе игнорируется (см. [issue-55](issue-55-guest-apply-delivery-ignores-order-id.md));
- мёртвый код уезжает в релизный архив и продолжает всплывать в ревью.

## Рекомендация

1. Удалить контроллер.
2. Убрать `FrontendFillCheckoutParams` из списка AJAX-контроллеров в `CLAUDE.md`.
3. Если функция когда-нибудь понадобится — это `ForcePrefill` с его проверкой `isDebug() && isAdmin('shop')`, отдельный эндпоинт не нужен.
