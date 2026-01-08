# Шпаргалка по локализации плагина Minorder

## 🚀 Основные команды

### Компиляция переводов

```bash
cd /Users/user/Project/wa-dev && php wa.php locale shop/plugins/prefill
```

### Очистка кэша

```bash
# Кэш локализации
rm -f wa-cache/*/apps/shop_prefill/locale/*.php

# Весь кэш плагина
find wa-cache -name "*.php" -path "*/shop_prefill/*" -delete
```

## 📝 Быстрый workflow

1. Редактируем `.po` файлы
2. Компилируем: `php wa.php locale shop/plugins/prefill`
3. Очищаем кэш: `rm -f wa-cache/*/apps/shop_prefill/locale/*.php`
4. Обновляем страницу (Ctrl+Shift+R)

## 💡 Использование в коде

### PHP

```php
// В action классе
waLocale::loadByDomain(array('shop', 'prefill'));
waSystem::pushActivePlugin('prefill', 'shop');

// Использование
$message = _wp('error.file-not-uploaded');
```

### Smarty

```smarty
{* Краткая форма *}
[`error.file-not-uploaded`]

{* Полная форма *}
{'error.file-not-uploaded'|_wp}

{* Для JavaScript *}
"{'error.file-not-uploaded'|_wp|escape:'javascript'}"
```

### JavaScript

```smarty
{* В шаблоне - определение *}
<script>
  var $_locale = {
    'Error': "{'Error'|_wp|escape:'javascript'}",
    'error.file-not-uploaded': "{'error.file-not-uploaded'|_wp|escape:'javascript'}"
  };
  window.$_ = function(key) { return $_locale[key] || key; };
</script>

{* В JS файле - использование *}
<script>
  var message = $_("error.file-not-uploaded");
</script>
```

## 🔍 Полная документация

См. [LOCALIZATION.md](LOCALIZATION.md) для детального руководства
