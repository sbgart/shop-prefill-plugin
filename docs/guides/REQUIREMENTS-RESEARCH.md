# Исследование: Как работает requirements.php в Webasyst

**Дата:** 2026-01-06  
**Плагин:** Minorder  
**Вопрос:** Как правильно указывать минимальную версию и когда проверяются требования?

---

## Формат версии в requirements.php

### Парсинг оператора версии

Код проверки версий в `wa-installer/lib/classes/wainstallerrequirements.class.php` (метод `getRelation()`):

```php
private function getRelation(&$version, $strict = false)
{
    $relation = $strict ? false : '>=';
    if (preg_match('/^(<|<=|=|>|>=)\s*(\d+.*)$/', $version, $matches)) {
        $relation = $matches[1];
        $version = $matches[2];
    }
    return $relation;
}
```

**Логика:**

1. По умолчанию используется оператор `>=` (если `$strict = false`)
2. Если версия начинается с оператора (`<`, `<=`, `=`, `>`, `>=`), он извлекается
3. Оператор удаляется из строки версии
4. Проверка выполняется через `version_compare($current_version, $required_version, $operator)`

**Вывод:** Оба варианта эквивалентны:

```php
'version' => '>=10.0.0'  // явно указываем оператор
'version' => '10.0.0'    // будет использоваться >= по умолчанию
```

---

## Примеры из официальных плагинов Shop-Script

### Brands (минималистичный)

```php
<?php
return array(
    'app.installer' => array(
        'strict'  => true,
        'version' => 'latest', // '>=1.14.11'
    ),
);
```

### Chestnyznak (с явным оператором)

```php
<?php
return array(
    'app.shop' => array(
        'strict'  => true,
        'version' => '>=8.11.0',
    ),
);
```

### CML1C (детальные требования с операторами)

```php
<?php
return array(
    'php.dom'       => array(
        'strict' => true,
        'value'  => 1,
    ),
    'php.libxml'    => array(
        'strict' => true,
        'value'  => 1,
    ),
    'php.xmlreader' => array(
        'strict' => true,
        'value'  => 1,
    ),
    'php.iconv'     => array(
        'strict' => true,
        'value'  => 1,
    ),
    'app.installer' => array(
        'strict'  => true,
        'version' => '>=1.4.0.40859',
    ),
    'app.shop'      => array(
        'strict'  => true,
        'version' => '>=6.1.0.40860',
    ),
    'php.zip'       => array(
        'strict' => false,
    ),
);
```

### Yandexmarket (смешанный подход)

```php
<?php
return array(
    'php.dom'       => array(
        'strict' => true,
        'value'  => 1,
    ),
    'app.shop'      => array(
        'strict'  => true,
        'version' => 'latest',
    ),
    'app.installer' => array(
        'strict'  => true,
        'version' => '1.11.9',  // без оператора = >= по умолчанию
    ),
);
```

---

## Когда проверяются требования?

### ✅ Проверяется при установке через Installer (магазин Webasyst)

**Файл:** `wa-installer/lib/classes/wainstallerapps.class.php`

**Метод:** `checkRequirements()`

```php
public static function checkRequirements(&$requirements, $update_config = false, $action = false)
{
    // ...
    foreach ($requirements as $subject => & $requirement) {
        $requirement['passed'] = false;
        $requirement['note'] = null;
        $requirement['warning'] = false;
        $requirement['update'] = $update;

        waInstallerRequirements::test($subject, $requirement);

        $passed = $requirement['passed'] && $passed;
        // ...
    }
    return $passed;
}
```

**Где вызывается:**

- При установке из магазина Webasyst Store
- При обновлении через Installer App
- При установке через API `installer.product.install`

### ❌ НЕ проверяется при ручной установке через загрузку файла

**Важное наблюдение:**

Изучив код `wa-system/plugin/waPlugin.class.php`, метод `install()`:

```php
protected function install()
{
    $file_db = $this->path.'/lib/config/db.php';
    if (file_exists($file_db)) {
        $schema = $this->includeConfig($file_db);
        $model = new waModel();
        $model->createSchema($schema);
    }
    // check install.php
    $file = $this->path.'/lib/config/install.php';
    if (file_exists($file)) {
        $this->includeCode($file);
        // ...
    }
}
```

**Нет проверки requirements!**

При ручной установке плагина:

1. Загрузка `.tar.gz` файла через "Установить плагин" → "Загрузить файл"
2. Распаковка архива
3. Вызов `install()` для создания схемы БД
4. Выполнение `lib/config/install.php`

**❗ Requirements НЕ проверяются при бета-тесте (установка через файл)**

---

## Рекомендации для плагина Minorder

### Текущая конфигурация (корректная)

```php
<?php
return [
    'php' => [
        'name'        => 'PHP',
        'description' => 'Минимальная версия PHP для работы плагина',
        'strict'      => true,
        'version'     => '>=7.4',
    ],
    'app.shop' => [
        'name'        => 'Shop-Script',
        'description' => 'Минимальная версия Shop-Script с поддержкой Webasyst UI 2.0',
        'strict'      => true,
        'version'     => '>=10.0.0',
    ],
];
```

### Альтернативный вариант (тоже корректный)

```php
<?php
return [
    'php' => [
        'name'        => 'PHP',
        'description' => 'Минимальная версия PHP для работы плагина',
        'strict'      => true,
        'version'     => '7.4',  // без оператора = >= по умолчанию
    ],
    'app.shop' => [
        'name'        => 'Shop-Script',
        'description' => 'Минимальная версия Shop-Script с поддержкой Webasyst UI 2.0',
        'strict'      => true,
        'version'     => '10.0.0',  // без оператора = >= по умолчанию
    ],
];
```

**Оба варианта эквивалентны!**

---

## Выводы

1. **Формат версии:** Можно использовать как `'version' => '>=10.0.0'`, так и `'version' => '10.0.0'` — они эквивалентны
2. **Проверка при установке из магазина:** ✅ Да, требования строго проверяются
3. **Проверка при бета-тесте (файл):** ❌ Нет, requirements НЕ проверяются при ручной установке
4. **Официальные плагины:** Используют оба стиля (с операторами и без)
5. **Рекомендация:** Использовать явный оператор `>=` для ясности и читаемости кода

### Практические следствия

- При бета-тестировании через загрузку файла плагин установится даже на несовместимых версиях
- Требования будут проверены только при публикации в магазине Webasyst
- Для защиты от несовместимости при бета-тесте можно добавить проверку в `lib/config/install.php`:

```php
<?php
// lib/config/install.php
$shop_version = wa('shop')->getVersion();
if (version_compare($shop_version, '10.0.0', '<')) {
    throw new waException('This plugin requires Shop-Script 10.0.0 or higher. Current version: ' . $shop_version);
}
```

---

**Источники:**

- `wa-installer/lib/classes/wainstallerrequirements.class.php` (метод `getRelation()`)
- `wa-installer/lib/classes/wainstallerapps.class.php` (метод `checkRequirements()`)
- `wa-system/plugin/waPlugin.class.php` (метод `install()`)
- Официальные плагины: brands, chestnyznak, cml1c, yandexmarket
