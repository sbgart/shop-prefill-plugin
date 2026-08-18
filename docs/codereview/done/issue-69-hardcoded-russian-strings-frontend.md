# Issue 69 — Русский текст зашит в JS и в дефолтных настройках: на нерусской витрине он виден покупателю

**Статус:** ✅ Решена
**Приоритет:** 🟠 Средний (блокер для продажи с заявленной локалью `en_US`)
**Сложность фикса:** 🔧 Небольшой
**Файлы:** `js/modules/ParamsChoiceManager.js`, `js/modules/DialogManager.js`, `js/modules/HttpClient.js`, `lib/classes/hooks/shopPrefillPluginFrontendHooks.class.php` (`initializeFrontendAssets`), `lib/config/storefront.settings.php`

## 1. Кнопка «Мои варианты» не локализована вообще

```js
// ParamsChoiceManager.js:188-190
paramsChoiceLink.textContent = this.messages.params_choice_link || "Мои варианты";
paramsChoiceLink.setAttribute("data-title",
    this.messages.params_choice_link_tooltip || "Мои варианты доставки из прошлых заказов");
```

Ключей `params_choice_link` и `params_choice_link_tooltip` **нет**:

- их не передаёт PHP — в `initializeFrontendAssets()` в `messages` кладутся только `validation_error_*`, `dialog_choose_delivery`, `delivery_unavailable_*`, `consent_revoke_*`;
- их нет ни в `locale/ru_RU/.../shop_prefill.po`, ни в `en_US` (там есть только `dialog.params_choice.empty`).

То есть фолбэк — не фолбэк, а единственная рабочая ветка. Единственная видимая кнопка плагина в секции доставки на английской витрине подписана по-русски.

## 2. Хардкод в DialogManager и HttpClient

```js
// DialogManager.js
throw new Error("Метод showDialog не поддерживается этим браузером.");   // :54
contentDiv.innerHTML = '…prefill-dialog__loading">Готовим контент...</div>';  // :205
content = '…prefill-dialog__error">Ошибка получения контента, попробуйте позже.</div>'; // :210

// HttpClient.js:42
throw new Error("Что-то пошло не так.");
```

Строки 205 и 210 видны покупателю: это состояние загрузки и ошибки в диалоге выбора варианта.

## 3. Русский в дефолтном шаблоне сводки доставки

```php
// lib/config/storefront.settings.php:63
… {if $delivery_est_delivery}<br /><strong>Доставим {$delivery_est_delivery}</strong>{/if}
```

Дефолт применяется ко всем витринам, включая англоязычные, и в шаблон локализация не подставляется — это литерал в конфиге.

## Рекомендация

1. Добавить ключи `dialog.params_choice.link` и `dialog.params_choice.link_tooltip` в `.po` обеих локалей и передавать их в `messages` рядом с остальными; хардкод в JS оставить только как аварийный `""`.
2. Прогнать пользовательские строки DialogManager (`loading`, `error`) через тот же `messages`; технические `throw new Error(...)` — перевести на английский, они уходят в лог, а не покупателю.
3. Для `Доставим` — либо убрать слово из дефолтного шаблона (оставить `{$delivery_est_delivery}`), либо завести отдельные дефолты на локаль. Первое проще и честнее: срок и так приходит готовой фразой от плагина доставки.
4. Проверить остаток: `grep -rn "[А-Яа-я]" js/modules js/prefill.frontend.js` и то же по `lib/config/*.settings.php`. Debug-код (`prefill.debug.js`, `shopPrefillPluginDebug::renderErrorsDebugHtml`) — сознательное исключение, но это стоит зафиксировать в `docs/guides/LOCALIZATION.md`.
5. После правки `.po` — `/compile-plugin-mo` и пересборка бандла `/build-plugin-frontend`.

## Решение

1. Добавлены ключи `dialog.params_choice.link` / `dialog.params_choice.link_tooltip` в `.po` обеих локалей; `shopPrefillPluginFrontendHooks::initializeFrontendAssets()` кладёт их в `messages` (`params_choice_link`, `params_choice_link_tooltip`). Фолбэк в `ParamsChoiceManager.js` заменён с русского текста на `""` — теперь это аварийный случай, а не единственная рабочая ветка.
2. `DialogManager` получил конструктор `(messages)`; `loading`/`error` в `_renderContent()` берутся из новых ключей `dialog.content.loading` / `dialog.content.error` (`messages.dialog_content_loading` / `dialog_content_error`). `prefill.frontend.js` передаёт `params.messages` в `new DialogManager(...)`. Технические `throw new Error(...)` в `DialogManager.js` и `HttpClient.js` переведены на английский (они уходят в лог, покупатель их не видит).
3. Из дефолтного шаблона `storefront.settings.php` (`zen.groups.delivery.summary_template`) убрано слово «Доставим» — остался только `{$delivery_est_delivery}`, который и так приходит готовой фразой от плагина доставки.
4. Остаток проверен: `grep -rn "[А-Яа-я]" js/modules js/prefill.frontend.js` и по `lib/config/*.settings.php` — совпадения только в JSDoc-комментариях, customer-facing текста не осталось. Debug-исключение (`prefill.debug.js`, `shopPrefillPluginDebug`) задокументировано в `docs/guides/LOCALIZATION.md` (раздел «Осознанные исключения из локализации»).
5. `.po` синхронизированы (`php wa.php locale`), `.mo` пересобраны через `msgfmt`, кэш локали и Smarty очищен, JS-бандл (`prefill.frontend.min.js`) пересобран через `terser` — новые строки подтверждены в `.mo` и в бандле.
