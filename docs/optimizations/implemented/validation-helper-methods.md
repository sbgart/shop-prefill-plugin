# Validation Helper Methods

## Проблема

Код проверки ошибок дублировался в каждом checkout хуке (`checkoutRenderAuth`, `checkoutRenderShipping`, `checkoutRenderConfirm`). Сложно было отслеживать, где какие ошибки отлавливаются.

## Решение

Вынесены два helper метода для централизованной обработки ошибок:

### 1. extractCheckoutErrors()

Извлекает все типы ошибок из `$params`:
- Критические ошибки (`$params['errors']`)
- Auth delayed_errors (`$params['data']['auth']['delayed_errors']`)
- Details delayed_errors (`$params['data']['details']['delayed_errors']`)
- Service agreement (`$params['vars']['auth']['service_agreement']`)

Возвращает структурированный массив с флагом `has_errors`.

### 2. renderErrorsDebugHtml()

Генерирует единообразный HTML debug блок с:
- Названием секции (AUTH/SHIPPING/CONFIRM/REGION)
- Группировкой ошибок по типам
- Цветовой индикацией важности
- Рекомендациями по решению

## Использование

```php
public function checkoutRenderAuth(&$params)
{
    $errors_info = $this->extractCheckoutErrors($params);
    
    if (!$errors_info['has_errors']) {
        return ''; // Можно скрывать форму
    }
    
    return $this->renderErrorsDebugHtml($errors_info, 'AUTH SECTION');
}
```

## Преимущества

- **DRY** - код не дублируется
- **Единообразие** - одинаковый формат debug информации
- **Расширяемость** - легко добавить новые типы ошибок
- **Читаемость** - понятно, где и какие ошибки проверяются
- **Debugging** - видно все ошибки в каждой секции checkout

## Файлы

- `lib/shopPrefill.plugin.php` - helper методы
- `docs/concept/VALIDATION.md` - документация системы валидации
