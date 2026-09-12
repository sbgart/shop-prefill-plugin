# `refresh-debug` не требует `isAdmin('shop')`/CSRF, в отличие от соседних debug-эндпоинтов

**Статус:** 🔍 Найдено 12.09.2026 при прогоне `/plugin-test prefill full`, точечная регрессия ФО-12
**Приоритет:** 🟢 Мелочь (та же предпосылка, что и у [debug-panel-not-checkout-scoped.md](debug-panel-not-checkout-scoped.md) — оба флага off по умолчанию)
**Сценарий:** обнаружено при спот-чеке ФО-12 (L-04/L2.4) после коммитов 11.09.2026 (`eea2deb` — единая
CSRF/method-проверка для debug-эндпоинтов, `77dab64` — снапшот и `isDebugPanelEnabled()`), не входит явно
ни в один пункт TEST-PLAN.md — L-03/L-04 проверяли `refresh-debug` только под админом или при `debug=false`,
комбинация «debug=true + debug_panel=true + аноним» не прогонялась.

## Что ожидалось

Три соседних debug-эндпоинта (`force-prefill`, `clear-storage`, `reset-and-refill`) наследуют
`shopPrefillPluginFrontendDebugBaseController`, который в `isAllowed()` требует **все четыре** условия:
`POST` + `isDebug()` + `wa()->getUser()->isAdmin('shop')` + `shopPrefillPluginCsrfGuard::matchesCsrfToken()`
(`lib/classes/frontend/shopPrefillPluginFrontendDebugBaseController.class.php:24-29`). По аналогии и
`refresh-debug` должен требовать то же самое — он тоже отдаёт `collectCurrentState()` (внутренние ключи
источника, дамп сессии чекаута).

## Что получилось

`shopPrefillPluginFrontendRefreshDebugController` (`lib/actions/frontend/shopPrefillPluginFrontendRefreshDebug.controller.php`)
не наследует общий базовый класс — это самостоятельный `waJsonController`, который проверяет только
`$plugin->isDebugPanelEnabled()` (глобальный `debug` + настройка витрины `debug_panel`), без `isAdmin('shop')`
и без CSRF-токена:

```php
if (!$plugin->isDebugPanelEnabled()) {
    $this->errors = ['error' => 'Access denied'];
    return;
}
```

Живой замер 12.09.2026: анонимный `curl` (чистая банка кук) при `debug=true` (глобально, временно) +
`debug_panel=true` (уже стояло на витрине) получил полный HTML debug-снимка (`prefill-debug-state`,
секции «Контекст», статус Zen и т.д.) в ответ на `POST /prefill/refresh-debug/` без какой-либо
авторизации и без CSRF-токена — HTTP 200, `status: ok`. Контрольный запрос к `force-prefill` в той же
сессии/куках вернул `403 Access denied`, подтверждая разницу в защите. После возврата `debug=false`
`refresh-debug` снова корректно отдаёт `Access denied`.

## Область поражения

Только когда **оба** флага включены одновременно (тот же предохранитель, что и у
debug-panel-not-checkout-scoped.md): глобальный Webasyst `debug` и настройка плагина `debug_panel`. При
обычной эксплуатации ни один анонимный посетитель это не увидит. Утечка — собственная сессия визитёра
(`storage_dump`) плюс внутренние технические детали (уровень логирования, источник данных), без данных
чужих покупателей. Отличие от родственной находки: там дыра в том, ГДЕ рендерится панель (любая страница
вместо чекаута), здесь — в том, что ОДИН из четырёх её AJAX-эндпоинтов не защищён так же, как остальные
три, и может быть вызван напрямую (в т.ч. кросс-сайтовой формой — CSRF-токен не проверяется) без
необходимости даже открывать страницу с панелью.

## Рекомендация

Привести `RefreshDebugController` к тому же контракту, что и три соседних эндпоинта — либо унаследовать
`shopPrefillPluginFrontendDebugBaseController` (тогда `handle()` вызывает `collectCurrentState()` и рендерит
`DebugState`, как сейчас), либо явно добавить `isAdmin('shop')` + `matchesCsrfToken()` в его `execute()`.
Проверка после фикса: анонимный `curl -X POST /prefill/refresh-debug/` при `debug=true`+`debug_panel=true`
должен вернуть `403 Access denied`, как и три других эндпоинта.

## Связанное

Тот же класс риска и тот же предохранитель (оба флага off по умолчанию), что и
[debug-panel-not-checkout-scoped.md](debug-panel-not-checkout-scoped.md) — обе находки стоит решать вместе,
если/когда будет приниматься осознанное решение об охвате debug-панели.
