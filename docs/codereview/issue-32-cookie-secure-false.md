# Issue 32 — Security: cookie `secure=false` во всех setCookie-вызовах

**Статус:** ⬜ Открыта  
**Приоритет:** 🟠 Высокий  
**Сложность фикса:** ⚡ Минутный  
**Файлы:**
- `fillparams/shopPrefillPluginGuestHashStorage.class.php`  
- `consent/shopPrefillPluginConsentStorage.class.php`  
- `user/shopPrefillPluginUserProvider.class.php`

## Проблема

Все cookies плагина устанавливаются с `secure = false`:

```php
$this->getResponse()->setCookie(
    self::GUEST_HASH_COOKIE,
    $hash,
    time() + self::COOKIE_TTL,
    null,   // path
    '',     // domain
    false,  // secure ← TODO в коде, но не исправлено
    true    // httponly
);
```

Комментарий `// TODO: включить для production` присутствует во **всех трёх файлах**, но не реализован.

При HTTP-соединении (или MITM-атаке) cookie `prefill_guest_hash`, `prefill_consent` и `auth_token` могут быть перехвачены.

## Рекомендация

Определять `secure` динамически на основе протокола запроса:

```php
$is_secure = waRequest::isHttps();
```

Или через конфиг: `wa()->getConfig()->get('secure_cookies', true)`.

Применить ко всем трём файлам. Также рассмотреть добавление `SameSite=Lax` атрибута.
