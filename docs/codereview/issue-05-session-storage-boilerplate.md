# Issue 05 — `SessionStorageProvider` — дублирование шаблона «if null → из snapshot, иначе fill_params»

**Статус:** ⬜ Открыта  
**Приоритет:** 🟡 Низкий  
**Сложность фикса:** 🔨 Рефакторинг  
**Файл:** `sessionstorage/shopPrefillPluginSessionStorageProvider.class.php`, строки 228–400

## Проблема

Все 6 методов `prepare*SectionParams` имеют идентичную структуру:

```php
private function prepareSomeSectionParams(?FillParams $fill_params, array &$final, ?array $snapshot): void {
    if ($fill_params === null) return;
    if ($snapshot !== null) {
        $final['order']['section'] = $snapshot;
        return;
    }
    // специфичная логика...
}
```

Первые два блока повторяются 6 раз. При добавлении новой секции разработчик обязан не забыть скопировать бойлерплейт.

## Рекомендация

Вынести общий шаблон:

```php
private function prepareSectionWithSnapshot(
    string $section,
    ?array $snapshot,
    array &$final,
    callable $fill_callback
): void {
    if ($snapshot !== null) {
        shopPrefillPluginLog::debug("$section section restored from snapshot");
        $final['order'][$section] = $snapshot;
        return;
    }
    $fill_callback($final);
}
```
