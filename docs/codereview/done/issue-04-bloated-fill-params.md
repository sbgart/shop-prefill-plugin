# Issue 04 — `FillParams` — «раздутый» Data Object (645 строк)

**Статус:** ✅ Закрыта  
**Приоритет:** 🟢 Косметика  
**Сложность фикса:** 🔨 Рефакторинг  
**Файл:** `fillparams/shopPrefillPluginFillParams.class.php`

## Проблема

Класс содержит 645 строк из-за того, что каждый getter/setter написан руками для ~20 свойств. Половина геттеров имеет неинформативные PHPDoc `@return string|null` без пояснений. `mergeWith()` публичный, хотя используется только внутри.

## Рекомендация

PHP 7.4 позволяет использовать typed properties — PHPDoc можно убирать там, где тип уже понятен из сигнатуры. Метод `mergeWith` → сделать `private`. Рассмотреть упрощение через magic `__get`/`__set` только если нужна строгая типизация через всё приложение.

## Выполнено

- Удалены избыточные PHPDoc (`@return`/`@param`) у всех геттеров/сеттеров, где тип задан в сигнатуре.
- Оставлены осмысленные комментарии: `getShippingAddressCustom`, `setShippingAddressCustom`, `getCustomerType`, `setCustomerType`, `getAuthData`, `setAuthData`, `getAuthField`, `setAuthField`, `isSameDeliveryOption`.
- `mergeWith()` переведён в `private`.
- Объём файла: 645 → 540 строк (−105).
