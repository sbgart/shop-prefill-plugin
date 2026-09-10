# Issue 101 — `AGENTS.md` описывает архитектуру, вырезанную из плагина, и противоречит правилу P7

**Статус:** ✅ Исправлена 09.09.2026
**Приоритет:** 🟠 Важно до продажи
**Сложность фикса:** 🔧 Небольшой
**Файлы:** `AGENTS.md` (весь раздел Data Flow, Guest Data Flow, таблица классов, Important Notes),
`CLAUDE.md` (Data Flow, п. 2 и «Two rules the marker must obey»)

## Как это работает сейчас

`AGENTS.md` (5 сентября) и `CLAUDE.md` (7 сентября) — один документ в двух копиях, одна для
Codex, другая для Claude Code. Копия для Codex отстала не на редакцию, а на несколько
завершённых миграций. Дословно из `AGENTS.md`:

```markdown
1. `frontendHead` hook fires → `FillParamsProvider::getFillParams()` queries last order from DB
   (by `contact_id` for auth users, by `prefill_guest_hash` cookie for guests)
2. If `prefill.on_entry = true`, data is written into PHP session (`shop/checkout`)
   via `SessionStorageProvider::preFillCheckoutParams()`
...
- First visit: `prefill_guest_hash` cookie (SHA256, HTTP-only, 1 year) is created
- **No test suite** exists for this plugin — test manually in browser against a running Webasyst instance

| `fillparams/` | … `FillParamsStorage` — writes to PHP session (`shop/checkout`);
                  `GuestHashStorage` — manages `prefill_guest_hash` HTTP-only cookie + DB linkage |
```

Проверено грепом по рабочему дереву на 09.09.2026:

| Утверждение `AGENTS.md` | Факт |
|---|---|
| Предзаполнение идёт из `frontend_head` | Прямое нарушение **P7**: предзаполнение живёт только на `checkout_before_auth` (issue-63). `handleFrontendHead()` предзаполнением не занимается вовсе и говорит об этом отдельным абзацем |
| Настройка `prefill.on_entry` | `grep -rn "on_entry" lib` — 0 совпадений. Вырезана из v1.0, лежит в [бэклоге](../todo/on-entry-early-prefill.md) |
| Класс `FillParamsStorage` | Не существует (0 файлов) |
| Класс `GuestHashStorage` | Не существует (0 файлов); есть `shopPrefillPluginGuestTokenStorage` |
| Кука `prefill_guest_hash`, «SHA256 создаётся при первом визите» | Кука называется `prefill_guest_token`, значение — `bin2hex(random_bytes(32))`, и выдаётся она **только при оформлении заказа**, а не при первом визите. «Просмотр каталога не создаёт ничего» — это правило **P5**, и оно ровно про обратное |
| Хеш сохраняется в `shop_order_params` | В БД лежит производный lookup id, а не хеш куки — правило **P6**, ради которого issue-63 и переделывала схему |
| «**No test suite** exists for this plugin» | `tests/` содержит 19 файлов, все зелёные; `TEST-PLAN.md` §5 планирует ещё восемь |

`CLAUDE.md` живее, но и там остался снятый снапшот:

```markdown
2. That method: collects prefillable sections → fills what it can from `shop/prefill_snapshot`
   → … writes `shop/checkout`, snapshot and the marker
- it gates **only** the loader call, never the snapshot restore — snapshot works every request
```

`shop/prefill_snapshot` снят 22.08.2026 ([план](../plans/snapshot-removal-and-html-ownership.md)),
в `lib/` его нет; вторая строка описывает инвариант несуществующего механизма.

## Что из этого следует

Покупателя это не касается: `exclude.php` вычищает `*.md` из релизного архива, и обе копии
остаются в репозитории. Поэтому не 🔴 — релиз файл не блокирует.

Касается это следующей правки чекаут-логики. Оба файла — то, что агент читает **первым**, до
`RULES.md` и до кода. Агент, работающий по `AGENTS.md`, начнёт с трёх ложных предпосылок:
предзаполнение можно трогать из `frontend_head` (нарушение P7 с первой же строки), гостевая кука
выдаётся при входе на сайт (нарушение P5), тестов нет — значит прогонять нечего (их 19, и они
ловят именно ту логику, которую он собрался править). Две из трёх — те самые ошибки, на разбор
которых уже потрачены issue-63 и issue-53.

Прецедент в этом же проекте: [issue-92](issue-92-minor-findings-pass-3.md) — «документация
разошлась с кодом» после коммита 86656dc, закрыта в день находки. Здесь расхождение шире и
живёт дольше: `prefill_guest_hash` исчез из кода 16.08.2026, `on_entry` — тогда же, снапшот —
22.08.2026.

## Рекомендация

1. Перегенерировать `AGENTS.md` из актуального `CLAUDE.md` — они отличаются только шапкой
   (адресат) и хвостовым разделом «Imported Claude Cowork project instructions», который в
   `CLAUDE.md` отсутствует и должен сохраниться.
2. В `CLAUDE.md` (и, значит, в новой копии) поправить Data Flow: убрать `shop/prefill_snapshot`
   из шага 2 и из «Two rules the marker must obey» — второе правило теперь читается как
   «маркер гейтит только вызов загрузчика», без второй половины.
3. Заменить утверждение «No test suite exists» на фактическое: `tests/*Test.php`, запуск
   `for t in tests/*Test.php; do php "$t"; done` (в `CLAUDE.md` это уже написано верно).
4. Чтобы не расходились снова — держать один файл, а второй сделать симлинком на него, как
   сделано со слэш-командами в `.claude/commands/`. Расхождение шапки при этом теряется; если
   она нужна, оставить копию, но перегенерацию `AGENTS.md` внести пунктом в
   [RELEASE-PROCESS.md](../guides/RELEASE-PROCESS.md).

Проверка: `diff CLAUDE.md AGENTS.md` должен показывать только шапку и раздел Cowork;
`grep -n "prefill_guest_hash\|on_entry\|FillParamsStorage\|GuestHashStorage\|prefill_snapshot\|No test suite" CLAUDE.md AGENTS.md`
— пусто.

## Фикс (09.09.2026)

`AGENTS.md` перегенерирован из актуального `CLAUDE.md`: расходятся теперь только заголовок
(«This file provides guidance to Codex…» вместо «…Claude Code (claude.ai/code)…») и хвостовой
раздел «Imported Claude Cowork project instructions», который сохранён без изменений.
`prefill_guest_hash`, `on_entry`, `FillParamsStorage`, `GuestHashStorage`, «No test suite
exists» — всё убрано вместе с остальным текстом-источником.

Заодно поправлен и сам `CLAUDE.md`: в Data Flow (шаг 2 и «Two rules the marker must obey»)
убран снятый `shop/prefill_snapshot` — описание приведено к фактическому поведению
`SessionStorageProvider::applyPrefill()` (выбор доступных секций → при несовпадении маркера
разовый вызов загрузчика → merge в `shop/checkout`).

Пункт 4 рекомендации (симлинк вместо второй копии) не сделан — это структурное решение шире
рамок точечного фикса, оставлено на усмотрение пользователя.

Проверка: `grep -n "prefill_guest_hash\|on_entry\|FillParamsStorage\|GuestHashStorage\|prefill_snapshot\|No test suite" CLAUDE.md AGENTS.md`
— пусто в обоих файлах; `diff CLAUDE.md AGENTS.md` показывает только шапку и раздел Cowork.

## Связанное

Правила [P5, P6, P7](../concept/RULES.md) — то, чему противоречит текущий текст.
[issue-63](issue-63-guest-hash-lookup-full-scan.md) — переход на токен и lookup id, вынос
предзаполнения из `frontend_head`.
[План снятия снапшота](../plans/snapshot-removal-and-html-ownership.md).
[issue-92](issue-92-minor-findings-pass-3.md), [issue-62](issue-62-dead-unguarded-fill-checkout-endpoint.md)
— прошлые синхронизации этих же двух файлов с кодом.
