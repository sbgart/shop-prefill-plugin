# Анализ кода: shop-prefill-plugin

Глубокий разбор проблемных мест по уровню опасности.

---

## 🔴 Потенциальные баги

### 1. Неинициализированная переменная `$icon_url` в `renderCollapseBlock`

**Файл:** `zenmode/shopPrefillPluginZenMode.class.php`, строки 347–373

```php
if ($is_collapsed) {
    $icon_mode = $this->getIconDisplayMode();
    if ($icon_mode !== 'none') {
        if ($icon_mode === 'plugin') {
            $icon_url = $this->getGroupPluginLogo($group, $state); // может вернуть null
        }
        // ❌ Если $icon_mode === 'default', $icon_url НЕ инициализирован
        if (empty($icon_url)) {
            $icon_url = $this->getGroupIcon($group);
        }
    }
    // ❌ Если $icon_mode === 'none', $icon_url НЕ инициализирован
    $summary_html = ...;
}

$this->view->assign([
    'icon_url' => $icon_url ?? null, // null coalescing маскирует ошибку, но не устраняет
```

**Проблема:** При `icon_mode === 'default'` переменная `$icon_url` не объявляется перед проверкой `if (empty($icon_url))`, что приводит к PHP Notice. При `$is_collapsed = false` `$summary_html` тоже не инициализируется, но там `?? null` корректно применён позже.

**Рекомендация:** Инициализировать переменные явно в начале метода:
```php
$icon_url = null;
$summary_html = null;
```

---

### 2. Баг: `storeComment` пишет не туда

**Файл:** `orders/shopPrefillPluginOrderProvider.class.php`, строки 100–107

```php
public function storeComment(int $order_id, ?string $comment): bool
{
    // ...
    return $this->getOrderParamsModel()->setOne($order_id, 'comment', $comment);
    //                     ^^^^^^^^^^^^^
    // ❌ Сохраняет в shop_order_params, но comment — поле таблицы shop_order
}
```

**Проблема:** Комментарий сохраняется в `shop_order_params` (доп. параметры), тогда как стандартное поле `comment` живёт в основной таблице `shop_order`. При следующем заказе будет вычитываться из params, но при ручном редактировании заказа в бэкенде эти данные расходятся.

**Рекомендация:** Проверить логику — если сохранение в `shop_order_params` осознанное (для предзаполнения), то и вычитывание должно быть оттуда, что сейчас и происходит в `getFillParamsByOrderParams`. Тогда это не баг, но стоит добавить комментарий.

---

### 3. Неправильный источник данных для подтверждения заказа

**Файл:** `fillparams/shopPrefillPluginFillParamsProvider.class.php`, строки 322–326

```php
// Получаем данные о подтверждении
$confirm_params = $checkout_params['order']['payment'] ?? [];  // ❌ БАГИ: payment, не confirm!
if (isset($confirm_params['comment'])) {
    $fill_params->setComment($confirm_params['comment']);
}
```

**Проблема:** Переменная `$confirm_params` берётся из секции `payment`, тогда как комментарий находится в `confirm`. Поле `comment` из `payment` никогда не существует — поэтому `setComment` никогда не вызывается через `getFillParamsByCheckoutParams`. Это значит, комментарий никогда не сохраняется из текущего чекаута (только из старых заказов через `getFillParamsByOrderParams`).

**Рекомендация:**
```php
$confirm_params = $checkout_params['order']['confirm'] ?? [];
```

---

## 🟠 Архитектурные проблемы

### 4. `FillParams` — «раздутый» Data Object (645 строк)

**Файл:** `fillparams/shopPrefillPluginFillParams.class.php`

Класс содержит 645 строк из-за того, что каждый getter/setter написан руками для ~20 свойств. Половина геттеров имеет неинформативные PHPDoc `@return string|null` без пояснений. `mergeWith()` публичный, хотя используется только внутри.

**Рекомендация:** PHP 7.4 позволяет использовать typed properties — PHPDoc можно убирать там, где тип уже понятен из сигнатуры. Методы `mergeWith` → сделать `private`. Рассмотреть упрощение через magic `__get`/`__set` только если нужна строгая типизация через всё приложение.

---

### 5. `SessionStorageProvider` — дублирование шаблона «if null → из snapshot, иначе fill_params»

**Файл:** `sessionstorage/shopPrefillPluginSessionStorageProvider.class.php`, строки 228–400

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

**Рекомендация:** Вынести общий шаблон:
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

---

### 6. `OrderProvider` — неинкапсулированный доступ к `waRequest` в хуке

**Файл:** `hooks/shopPrefillPluginOrderHooks.class.php`, строки 82–85

```php
// Если в сессии нет — читаем прямо из POST
$shipping_post = waRequest::post('shipping', [], waRequest::TYPE_ARRAY_TRIM);
```

**Проблема:** Статичный вызов `waRequest::post()` в классе `OrderHooks` нарушает принципы DI — OrderHooks знает о глобальном состоянии. При тестировании невозможно подменить запрос.

**Рекомендация:** Инжектировать `waRequest` в `OrderHooks` через конструктор (аналогично `CheckoutHooks`).

---

### 7. `AssetsManager` — магические пути в строках

**Файл:** `view/shopPrefillPluginAssetsManager.class.php`, строки 63–73

```php
$this->getResponse()->addCss(
    substr(wa()->getDataUrl('plugins/' . $this->plugin_id . '/css/', true, 'shop'), 1)
    . $css_variables_filename
);
```

`substr(..., 1)` — неочевидный трюк для обрезки ведущего `/`. Логика пути дублируется для CSS и JS (строки 63 и 71).

**Рекомендация:** Вынести в метод:
```php
private function getPublicDataPath(string $subdir): string
{
    return substr(wa()->getDataUrl('plugins/' . $this->plugin_id . '/' . $subdir . '/', true, 'shop'), 1);
}
```

---

### 8. `getStorefrontSettings()` — экземплярный метод делегирует в статик

**Файл:** `shopPrefill.plugin.php`, строки 130–133

```php
public function getStorefrontSettings(): array
{
    return self::$storefront_settings ??= self::getStorefrontProvider()->getCurrentStorefront()->getSettings();
    //                                          ^^^
    // getStorefrontProvider() — instance метод, вызывается через self
}
```

`self::getStorefrontProvider()` вызывает instance-метод через псевдостатику. В PHP 7.4 это работает, но запутывает — по записи кажется статиком.

**Рекомендация:** `$this->getStorefrontProvider()->...`

---

## 🟡 Потенциал оптимизации (N+1, лишние запросы)

### 9. N+1 запросов при сборе коллекции доставок

**Файл:** `fillparams/shopPrefillPluginFillParamsProvider.class.php`, строки 192–197

```php
foreach ($orders_ids as $order_id) {
    $params = $this->getOrderProvider()->getOrderParams($order_id); // ← N запросов к БД
    if ($params) {
        $orders_params[$order_id] = $params;
    }
}
```

`getOrderParams()` делает отдельный SQL-запрос для каждого заказа. При 20 заказах в истории — 20 запросов.

**Рекомендация:** Использовать уже существующий метод `getUserOrdersParams()` из `OrderProvider`:
```php
// Один запрос для всех заказов сразу
$orders_params = $this->getOrderProvider()->getUserOrdersParams($contact_id);
```

> ⚠️ Аналогичный метод есть для авторизованных, но для гостей (`getAllOrderIdsByGuestHash`) тоже нужен батчевый вариант.

---

### 10. Двойной вызов `getFillParams()` в `handleFrontendHead`

**Файл:** `hooks/shopPrefillPluginFrontendHooks.class.php`, строки 67–82

```php
$fill_params = $this->fill_params_provider->getFillParams(); // ← 1-й вызов

// ...
if ($this->storefront_settings['prefill']['on_entry']) {
    $this->session_storage->preFillCheckoutParams(
        $this->fill_params_provider->getFillParams() // ← 2-й вызов (дублирует запросы к БД!)
    );
}
```

`getFillParams()` не кэшируется внутри `FillParamsProvider` — каждый вызов делает запросы к БД (поиск последнего заказа, загрузка параметров).

**Рекомендация:** Повторно использовать уже полученный `$fill_params`:
```php
if ($this->storefront_settings['prefill']['on_entry']) {
    $this->session_storage->preFillCheckoutParams($fill_params);
}
```

---

### 11. Статические кэши `PluginsProvider` мутируются при сортировке

**Файл:** `plugins/shopPrefillPluginPluginsProvider.class.php`

```php
private static ?array $shipping_methods_cache = null;
private static ?array $payment_methods_cache = null;
```

В рамках одного PHP-запроса это нормально. Но метод `getSortedShippingMethods` **мутирует элементы кэша** через `&$shipping` (строка 35), при следующем вызове `getShippingMethods()` вернётся уже изменённый массив.

**Рекомендация:** Убрать `&` (pass by reference) в `getSortedShippingMethods` или работать с копией.

---

## 🟢 Косметика и DRY

### 12. `OrderProvider` — геттеры для полей-инъекций бессмысленны

**Файл:** `orders/shopPrefillPluginOrderProvider.class.php`, строки 16–24

```php
private function getOrderModel(): ?shopOrderModel { return $this->order_model; }
private function getOrderParamsModel(): ?shopOrderParamsModel { return $this->order_params_model; }
```

Оба поля инициализируются в конструкторе и никогда не могут быть `null` после этого. Возвращаемый тип `?Model` вводит в заблуждение, а геттеры не несут смысловой нагрузки.

**Рекомендация:** Обращаться напрямую к `$this->order_model`, убрать nullable типы.

---

### 13. `checkoutBeforeAuth` — проверяет `null`, но метод никогда не возвращает `null`

**Файл:** `hooks/shopPrefillPluginCheckoutHooks.class.php`, строки 53–56

```php
$fill_params = $this->fill_params_provider->getFillParams();
if (!$fill_params) {  // ← getFillParams() возвращает FillParams, никогда не null
    return;
}
```

`getFillParams()` объявлен с типом `: shopPrefillPluginFillParams` — всегда возвращает объект. Проверка `if (!$fill_params)` — мёртвый код.

**Рекомендация:** Удалить проверку. Если нужна защита «нет данных», проверять `$fill_params->isActive()` или `$fill_params->hasDataForSection('auth')`.

---

### 14. `isUserAuthenticated()` в `SessionStorageProvider` — дублирует `UserProvider`

**Файл:** `sessionstorage/shopPrefillPluginSessionStorageProvider.class.php`, строки 210–220

```php
private function isUserAuthenticated(): bool
{
    try {
        return wa()->getUser()->isAuth();
    } catch (waException $e) { ... }
}
```

`shopPrefillPluginSessionStorageProvider` не имеет инжекции `UserProvider`, поэтому сделал отдельный метод с прямым обращением к `wa()`. Это нарушает DI: провайдер напрямую обращается к глобальному состоянию.

**Рекомендация:** Инжектировать `shopPrefillPluginUserProvider` в `SessionStorageProvider`.

---

### 15. `mergeWith` — незащищённое обращение к приватным свойствам

**Файл:** `fillparams/shopPrefillPluginFillParams.class.php`, строки 638–643

```php
public function mergeWith(shopPrefillPluginFillParams $other, array $properties): void
{
    foreach ($properties as $property) {
        $this->$property = $other->$property; // динамический доступ к свойству
    }
}
```

Динамический доступ `$other->$property` к приватным свойствам работает только потому, что оба объекта одного класса. PHP это разрешает, но при опечатке или неправильном имени — `Undefined property` в рантайме без явной ошибки компилятора.

**Рекомендация:** Оставить как есть (работает корректно), но добавить assert или проверку `property_exists`.

---

## Итоговые приоритеты

| # | Проблема | Приоритет | Сложность фикса |
|---|----------|-----------|-----------------|
| 3 | Баг: confirm берётся из `payment` секции | 🔴 Высокий | ⚡ Минутный |
| 1 | Неинициализированная переменная `$icon_url` | 🔴 Высокий | ⚡ Минутный |
| 10 | Двойной вызов `getFillParams()` | 🟠 Средний | ⚡ Минутный |
| 9 | N+1 запросов в `getFillParamsCollection` | 🟠 Средний | 🔧 Небольшой |
| 11 | Мутация статического кэша в `PluginsProvider` | 🟠 Средний | ⚡ Минутный |
| 5 | Дублирование шаблона в `prepare*SectionParams` | 🟡 Низкий | 🔨 Рефакторинг |
| 6 | Статический `waRequest::post()` в `OrderHooks` | 🟡 Низкий | 🔧 Небольшой |
| 14 | `isUserAuthenticated` без DI в `SessionStorageProvider` | 🟡 Низкий | 🔧 Небольшой |
| 4 | Раздутый `FillParams` (645 строк) | 🟢 Косметика | 🔨 Рефакторинг |
| 8 | `self::getStorefrontProvider()` вместо `$this->` | 🟢 Косметика | ⚡ Минутный |
| 13 | Мёртвый код `if (!$fill_params)` в `checkoutBeforeAuth` | 🟢 Косметика | ⚡ Минутный |
| 12 | Бессмысленные геттеры в `OrderProvider` | 🟢 Косметика | ⚡ Минутный |
