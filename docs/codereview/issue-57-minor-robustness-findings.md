# Issue 57 — Мелкие находки ревью (надёжность и гигиена)

**Статус:** 🟡 Частично закрыта
**Приоритет:** 🟢 Низкий
**Сложность фикса:** 🔧 Тривиальные

Сборник мелочей, найденных при ревью перед релизом v1.0. Каждый пункт независим.

## 1. `getStorefront($code)` может вернуть `null` — ✅ закрыто в [issue-49](issue-49-fatal-storefront-null-backend-order-create.md)

`shopPrefillPluginStorefrontProvider::getStorefront()` ищет витрину в коллекции и возвращает `null`, если кода нет. Использования без проверки:

- `shopPrefillPlugin::saveSettings()` → `->saveSettings($storefront_settings)` на `null`;
- `SettingsGetCss` / `SettingsStorefront` → `->getSettings()` на `null` (код витрины приходит из запроса);
- `resolveStorefrontCssUrl()` → `getCurrentStorefront()->getCode()`.

Витрину могли удалить/переименовать между открытием формы и сохранением → фатал. Стоит валидировать код и возвращать понятную ошибку.

## 2. Cookies без `secure`

`prefill_guest_hash`, `prefill_consent` (в коде стоит TODO) и `auth_token` из [issue-51](issue-51-remember-me-auth-token-forced.md) ставятся с `secure = false`. На HTTPS-магазине — выставлять `waRequest::isHttps()`. `httponly` везде корректный.

## 3. Сгенерированные файлы в `wa-data` не удаляются

`shopPrefillPluginAssetsManager::generateCssVariablesFile()` / `generateJSInitializerFile()` пишут файл на каждый новый хеш параметров и никогда не чистят старые. На тестовой установке накопилось 15 CSS + 20 JS. Нужна чистка старых файлов при сохранении настроек (или единый файл с версией из `update_time`).

## 4. `shop_prefill_settings` без уникального индекса

В `lib/config/db.php` только `PRIMARY = id`. `shopPrefillPluginSettingsModel::set()` делает `getByField()` + `insert()/updateByField()` — при параллельных сохранениях возможны дубли строк `(storefront_code, name, groups)`, а `parse()` тихо возьмёт последнюю. Добавить уникальный ключ.

## 5. Логи без ротации

`shopPrefillPluginLog::writeLog()` пишет в `wa-log/prefill.plugin*.log` через `file_put_contents(..., FILE_APPEND)` без ограничения размера; читается только последний 1 МБ (`LogReader::MAX_BYTES`), остальное копится на диске вечно. См. также [issue-52](issue-52-consent-endpoint-log-flood-csrf.md).

## 6. `zenmode.css` подключается без cache-busting — ✅ закрыто

`CheckoutHooks::renderZenModeStylesheet()` отдаёт `<link href=".../css/zenmode.css">` без `?v=`. После обновления плагина у покупателей останется старый CSS из кеша браузера. Добавить версию плагина в URL.

Исправлено: версия плагина передаётся в `CheckoutHooks` через конструктор и добавляется к URL как `?v=<версия>`. Прямого обращения к `shopPrefillPlugin::getInstance()` из обработчика нет.

## 7. `.DS_Store` в репозитории

`.DS_Store` лежит в `plugins/prefill/`, `docs/`, `lib/`, `templates/`, `locale/`. В архив они не попадают, но в git отслеживаются — почистить (`git rm --cached`), в `.gitignore` правило уже есть.

## 8. `custom_css` хранится в колонке `TEXT`

Лимит 64 КБ. Текущий `frontend.css` — 11 КБ, запас есть, но при вставке большого CSS MySQL молча обрежет значение. Либо `MEDIUMTEXT`, либо валидация длины в UI.
