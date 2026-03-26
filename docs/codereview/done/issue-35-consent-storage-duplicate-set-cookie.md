# Issue 35 — `ConsentStorage`: дублирование setCookie в `grantConsent` и `renewConsent`

**Статус:** ✅ Готово  
**Приоритет:** 🟢 Низкий  
**Сложность фикса:** ⚡ Минутный  
**Файл:** `consent/shopPrefillPluginConsentStorage.class.php`

## Проблема

Методы `grantConsent()` и `renewConsent()` содержат идентичный код:

```php
private function renewConsent(): void
{
    $this->response->setCookie(
        self::CONSENT_COOKIE, '1',
        time() + self::COOKIE_TTL, null, '', false, true
    );
}

public function grantConsent(): void
{
    $this->response->setCookie(
        self::CONSENT_COOKIE, '1',
        time() + self::COOKIE_TTL, null, '', false, true
    );
}
```

Два полностью идентичных блока в 7 строк каждый.

## Рекомендация

`grantConsent()` может делегировать в `renewConsent()`:

```php
public function grantConsent(): void
{
    $this->renewConsent();
}
```

Или наоборот — переименовать `renewConsent` в `setConsentCookie` и вызывать из обоих мест.

