# Диалог выбора области сохранения (SaveScopeDialog) не поддерживает тёмную тему

**Статус:** исправлено 07.09.2026.

## Фикс

По образцу `.prefill-confirm-dialog` в `css/prefill.settings.css` добавлены правила
`html[data-theme="dark"] .prefill-save-scope-dialog .wa-dialog-background` и
`.prefill-save-scope-dialog .wa-dialog-body` (`background: var(--background-color-blank)`,
`color: var(--text-color)`, тень). Проверено в браузере на бэкенде в тёмной теме — диалог
«Сохранение настроек» теперь тёмный, читаемый.

## Наблюдение

Найдено 30.08.2026 на Docker-стенде (UI 2.0, тёмная тема) при сохранении настроек плагина на
нескольких открытых витринах — вылезает белый диалог «Saving settings» на тёмном фоне остального
интерфейса.

Причина видна прямо в CSS: для диалога редактора шаблона (`.prefill-ct-dialog-body`,
`css/prefill.settings.css:409-415`) есть явный комментарий и фикс — legacy `.wa-dialog-body`
хардкодит белый фон/чёрный текст (`#fff`), поверх него в коде явно накинуты
`background: var(--background-color-blank)` и `color: var(--text-color)`. Для
`.prefill-save-scope-dialog .wa-dialog-body` (`css/prefill.settings.css:612-614`) такого
переопределения нет — только `max-width`. Тот же самый баг, что уже когда-то чинили для одного
диалога, просто не перенесли на второй.

## Не разобрано

- Не проверено, есть ли третий подобный диалог (`ContactsDialog.html`?) с той же дырой.
