# Автопродление cookies гостя при каждом визите

**Дата реализации:** 2026-01-16  
**Статус:** ✅ Реализовано

## Проблема

Cookie `prefill_guest_hash` продлевалась только при предзаполнении корзины, а не при каждом визите на сайт:

- **До изменения:** Cookie продлевалась только когда:
  - Пользователь заходил в корзину (`frontendOrder`)
  - Предзаполнение включено и `on_entry = true` (`frontendHead` → `getFillParams()`)
  
- **Проблема:** Если пользователь периодически заходил на сайт, но не заходил в корзину, cookie через год истекала, и связь с прошлыми заказами терялась.

## Решение

Добавлено автоматическое продление **двух типов cookies** для неавторизованных пользователей в хук `frontendHead`:

### 1. Cookie хеша гостя (`prefill_guest_hash`)

```php
// lib/shopPrefill.plugin.php - метод frontendHead()

// Для неавторизованных: продлеваем cookie хеша гостя и согласия при каждом визите
// Это обеспечивает автоматическое продление срока жизни обоих cookies (1 год)
if (!$this->getUserProvider()->isAuth()) {
    $this->getGuestHashStorage()->getOrCreateGuestHash();
    
    // Продлеваем cookie согласия (если оно было дано)
    // Вызов hasConsent() автоматически продлевает cookie
    $this->getConsentStorage()->hasConsent();
}
```

**Механизм для `prefill_guest_hash`:**
- Метод `getOrCreateGuestHash()` проверяет наличие cookie
- Если есть → продлевает на 1 год и возвращает
- Если нет → создает новый хеш и сохраняет в cookie

### 2. Cookie согласия (`prefill_consent`)

```php
// lib/classes/consent/shopPrefillPluginConsentStorage.class.php

public function hasConsent(): bool
{
    $consent = waRequest::cookie(self::CONSENT_COOKIE, null, waRequest::TYPE_STRING);

    if ($consent === '1') {
        // Продлеваем cookie при каждой проверке
        $this->renewConsent();
        return true;
    }

    return false;
}

private function renewConsent(): void
{
    setcookie(
        self::CONSENT_COOKIE,
        '1',
        time() + self::COOKIE_TTL, // Продлеваем на 1 год
        '/',
        '',
        false,
        true // httponly
    );
}
```

**Механизм для `prefill_consent`:**
- Метод `hasConsent()` проверяет наличие cookie
- Если согласие дано (`'1'`) → автоматически продлевает на 1 год через `renewConsent()`
- Если согласия нет → не создает cookie (требуется явное согласие от пользователя)

## Преимущества

- ✅ **Обе cookies не истекают**, пока пользователь периодически посещает сайт
- ✅ Не требуется заходить в корзину для продления
- ✅ Работает **на всех страницах** магазина
- ✅ Совместимо с существующей логикой (не нарушает предзаполнение)
- ✅ **Consent cookie** продлевается только если согласие было дано (безопасно)

## Обновленная документация

- **CONCEPT.md:**
  - Строка 105: уточнен срок жизни cookie хеша гостя
  - Строка 232: уточнен срок жизни cookie согласия
  - Раздел "Механизм работы": добавлены пункты об автопродлении через `frontendHead`
  
- **shopPrefillPluginGuestHashStorage:**
  - Обновлены PHPDoc комментарии класса и метода `getOrCreateGuestHash()`

- **shopPrefillPluginConsentStorage:**
  - Добавлен приватный метод `renewConsent()`
  - Обновлены PHPDoc комментарии класса и метода `hasConsent()`
  - Метод `hasConsent()` теперь автоматически продлевает cookie при проверке

## Влияние на производительность

**Минимальное:**
- Вызовы происходят только для неавторизованных пользователей
- `getOrCreateGuestHash()`: чтение cookie + установка cookie (если нужно)
- `hasConsent()`: чтение cookie + установка cookie (если согласие дано)
- Без запросов к БД

## Тестирование

### Сценарий 1: Первый визит гостя
1. Гость заходит на сайт впервые
2. ✅ Cookie `prefill_guest_hash` создается и устанавливается на 1 год
3. ✅ Cookie `prefill_consent` НЕ создается (требуется явное согласие)

### Сценарий 2: Повторный визит гостя (согласие дано)
1. Гость с `prefill_consent=1` заходит на сайт (не в корзину)
2. ✅ Cookie `prefill_guest_hash` автоматически продлевается на 1 год
3. ✅ Cookie `prefill_consent` автоматически продлевается на 1 год

### Сценарий 3: Повторный визит гостя (согласие не дано)
1. Гость без `prefill_consent` заходит на сайт
2. ✅ Cookie `prefill_guest_hash` автоматически продлевается на 1 год
3. ✅ Cookie `prefill_consent` не создается (требуется явное согласие)

### Сценарий 4: Авторизованный пользователь
1. Пользователь авторизован
2. ✅ Методы `getOrCreateGuestHash()` и `hasConsent()` НЕ вызываются

### Сценарий 5: Гость → авторизация → logout
1. Гость с обоими cookies заходит, авторизуется
2. ✅ Cookies сохраняются (могут пригодиться при logout)
3. ✅ При logout → снова работает как гость с теми же cookies

## Связанные файлы

- `lib/shopPrefill.plugin.php` - метод `frontendHead()`
- `lib/classes/fillparams/shopPrefillPluginGuestHashStorage.class.php`
- `lib/classes/consent/shopPrefillPluginConsentStorage.class.php` - добавлен метод `renewConsent()`
- `docs/concept/CONCEPT.md` - обновлена документация

## Техническое исправление

**Проблема:** При первой реализации использовался нативный `setcookie()`, что приводило к ошибке:
```
Warning: Cannot modify header information - headers already sent by ...
```

**Решение:** Заменены все вызовы `setcookie()` на `waResponse->setCookie()` во всех методах:
- `renewConsent()` - автоматическое продление
- `grantConsent()` - установка согласия
- `revokeConsent()` - отзыв согласия

**Преимущество:** `waResponse->setCookie()` корректно работает с системой управления заголовками Webasyst Framework, предотвращая конфликты с уже отправленным контентом.

## Примечания

- Автопродление происходит **только для неавторизованных**, так как авторизованные пользователи используют `contact_id` вместо хеша
- Обе cookies остаются HTTP-only для защиты от XSS атак
- Срок жизни обоих cookies фиксированный: 1 год (365 дней)
- Cookie согласия продлевается **только если оно было дано** (значение `'1'`), не создает новую cookie автоматически
