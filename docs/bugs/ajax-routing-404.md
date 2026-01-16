# Проблема: 404 при AJAX-запросах к контроллерам плагина

**Дата:** 2026-01-16  
**Статус:** ✅ Решено

## Симптомы

- AJAX-запрос к `/prefill/consent` возвращал 404
- В консоли браузера: `URL: https://wa-dev.loc/prefill/consent, Статус: 404`

## Причина

JavaScript формировал **неправильный путь** к экшенам плагина Shop-Script.

### Неправильно ❌
```javascript
$.post('/prefill/consent', { action: 'grant' })
```
→ URL: `https://wa-dev.loc/prefill/consent`

### Правильно ✅
```javascript
$.post(this.appUrl + 'prefill/consent', { action: 'grant' })
```
→ URL: `https://wa-dev.loc/shop/prefill/consent`

## Решение

1. **В PHP** добавить базовый URL приложения:
```php
$js_params = [
    'pluginID' => 'prefill',
    'appUrl' => wa()->getAppUrl('shop'),  // "/shop/"
    'isDebug' => true,
];
```

2. **В JavaScript** использовать полный путь:
```javascript
constructor(params) {
    this.appUrl = params.appUrl;  // Получаем из PHP
}

// При AJAX-запросах
$.post(this.appUrl + this.pluginID + '/consent', data)
```

## Правило

> **Для плагинов Shop-Script все AJAX-запросы к frontend-контроллерам должны использовать полный путь через приложение:**  
> `{appUrl}{pluginID}/{action}` → `/shop/prefill/consent`

## Файлы

- `lib/shopPrefill.plugin.php` (строка 408)
- `js/frontend.js` (строки 3, 109, 115, 200)
