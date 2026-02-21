# Посекционные флаги предзаполнения (Per-Section Prefill Flags)

## Проблемы

### Проблема 1: Zen Mode + ошибка валидации

При ошибке валидации (например, некорректный email) Shop-Script стирает данные скрытых секций при повторном обновлении формы.

**Сценарий:**

1. Пользователь заполнил: доставка ✅, оплата ✅, email ❌
2. Submit → валидация → ошибка email
3. Первый reload формы → данные ВСЁ ЕЩЁ в хранилище
4. Второй reload формы (смена количества товара):
   - Инпуты доставки/оплаты НЕ видимы (Zen Mode) → НЕ отправляются в POST
   - Shop-Script считает "данных нет" → **СТИРАЕТ секции доставки и оплаты**
   - Данные потеряны

### Проблема 2: Смена типа доставки стирает оплату

При смене типа доставки Shop-Script пересчитывает доступные способы оплаты и **стирает** текущий выбор оплаты из хранилища.

**Сценарий:**

1. Plugin предзаполнил: доставка "Курьер" ✅, оплата "Карта" ✅
2. Пользователь меняет доставку на "Самовывоз"
3. Shop-Script стирает `order['payment']` (оплата зависит от типа доставки)
4. Следующий запрос: `first_prefill_done = true` → плагин НЕ предзаполняет повторно
5. **Секция оплаты остаётся пустой**

### Общий корень обеих проблем

**Глобальный флаг `first_prefill_done` — единственная причина.**

```php
// SessionStorageProvider.php строка 73
if ($checkout_params['prefill_metadata']['first_prefill_done'] ?? false) {
    $this->prefilled = true;
    return; // ← блокирует ВСЕ повторные предзаполнения навсегда
}
```

После первого предзаполнения флаг = `true` и остаётся в `prefill_metadata`, которые Shop-Script никогда не стирает. Даже когда секции пустые — плагин игнорирует это.

---

## Что уже работает правильно

Посекционные независимые проверки в `SectionChecker` **уже реализованы**:

```php
// SectionChecker.php
canPrefillSection('auth', $checkout_params)    // auth.data.email / phone / firstname
canPrefillSection('region', $checkout_params)  // region.city
canPrefillSection('shipping', $checkout_params)// shipping.type_id
canPrefillSection('payment', $checkout_params) // payment.id  ← независимо!
canPrefillSection('confirm', $checkout_params) // confirm.comment
```

Payment и Confirm проверяются независимо от Shipping (старая coupling-проблема уже исправлена). Единственное, что нужно — **убрать глобальный флаг**.

---

## Предложенное решение

### Механизм: Посекционные флаги `_prefilled`

Вместо одного глобального флага использовать флаг **для каждой секции**, размещённый **внутри данных секции**:

```php
// При предзаполнении:
$checkout_params['order']['auth']['data']['_prefilled'] = 1;
$checkout_params['order']['shipping']['_prefilled'] = 1;
$checkout_params['order']['payment']['_prefilled'] = 1;
$checkout_params['order']['details']['shipping_address']['_prefilled'] = 1;
$checkout_params['order']['region']['_prefilled'] = 1;
$checkout_params['order']['confirm']['_prefilled'] = 1;
```

### Как это работает

1. **Предзаполнение:** При заполнении секции устанавливается флаг `_prefilled` внутри данных
2. **Стирание:** Когда Shop-Script стирает `order['auth']['data']`, флаг `_prefilled` стирается **автоматически**
3. **Проверка:** Перед предзаполнением проверяем:
   - Есть ли флаг `_prefilled`? → Да → НЕ заполняем (уже заполняли)
   - Нет флага `_prefilled` И нет данных? → Заполняем заново + ставим флаг

### Где размещать флаги

**Вариант C (выбран):** Внутри данных секции

| Секция     | Размещение флага                                     |
| ---------- | ---------------------------------------------------- |
| `auth`     | `order['auth']['data']['_prefilled']`                |
| `region`   | `order['region']['_prefilled']`                      |
| `shipping` | `order['shipping']['_prefilled']`                    |
| `details`  | `order['details']['shipping_address']['_prefilled']` |
| `payment`  | `order['payment']['_prefilled']`                     |
| `confirm`  | `order['confirm']['_prefilled']`                     |

**Плюсы:**

- ✅ Флаг **гарантированно** стирается вместе с данными секции
- ✅ Не зависит от логики очистки Shop-Script
- ✅ Простая реализация

### Изменения в коде

#### 1. Добавить методы работы с флагами

```php
// В shopPrefillPluginSessionStorageProvider

private function isSectionPrefilled(string $section, array $checkout_params): bool
{
    switch ($section) {
        case 'auth':
            return !empty($checkout_params['order']['auth']['data']['_prefilled']);
        case 'region':
            return !empty($checkout_params['order']['region']['_prefilled']);
        case 'shipping':
            return !empty($checkout_params['order']['shipping']['_prefilled']);
        case 'details':
            return !empty($checkout_params['order']['details']['shipping_address']['_prefilled']);
        case 'payment':
            return !empty($checkout_params['order']['payment']['_prefilled']);
        case 'confirm':
            return !empty($checkout_params['order']['confirm']['_prefilled']);
    }
    return false;
}

private function setSectionPrefilledFlag(string $section, array &$final_params): void
{
    switch ($section) {
        case 'auth':
            $final_params['order']['auth']['data']['_prefilled'] = 1;
            break;
        case 'region':
            $final_params['order']['region']['_prefilled'] = 1;
            break;
        case 'shipping':
            $final_params['order']['shipping']['_prefilled'] = 1;
            break;
        case 'details':
            $final_params['order']['details']['shipping_address']['_prefilled'] = 1;
            break;
        case 'payment':
            $final_params['order']['payment']['_prefilled'] = 1;
            break;
        case 'confirm':
            $final_params['order']['confirm']['_prefilled'] = 1;
            break;
    }
}
```

#### 2. Изменить логику `preFillCheckoutParams()`

```php
public function preFillCheckoutParams(shopPrefillPluginFillParams $params): void
{
    if ($this->prefilled) {
        return; // Уже вызвали в ЭТОМ HTTP-запросе
    }

    $checkout_params = $this->getCheckoutParams();
    $checkout_params = is_array($checkout_params) ? $checkout_params : [];

    // УБРАТЬ проверку глобального флага first_prefill_done

    $final_params = [];
    $checker = $this->getSectionChecker();

    // Для каждой секции НЕЗАВИСИМО:
    foreach (['auth', 'region', 'shipping', 'details', 'payment', 'confirm'] as $section) {
        // Проверяем: можно ли заполнить? (нет данных + нет флага _prefilled)
        if ($checker->canPrefillSection($section, $checkout_params)
            && !$this->isSectionPrefilled($section, $checkout_params)) {

            // Заполняем секцию
            $method = 'prepare' . ucfirst($section) . 'SectionParams';
            $this->$method($params, $final_params);

            // Ставим флаг _prefilled для этой секции
            $this->setSectionPrefilledFlag($section, $final_params);
        }
    }

    if (!empty($final_params)) {
        $this->setCheckoutParams(
            shopPrefillPluginHelper::deepMergeArrays($checkout_params, $final_params)
        );
    }

    $this->prefilled = true;
}
```

#### 3. Добавить предзаполнение в AJAX-обновления

```php
// В shopPrefill.plugin.php -> checkoutRenderAuth()

public function checkoutRenderAuth(&$params) {
    // ===== ПРЕДЗАПОЛНЕНИЕ (для AJAX reload формы) =====
    if ($storefront_settings['prefill']['active']) {
        $fill_params = $this->getFillParamsProvider()->getFillParams();
        if ($fill_params) {
            $this->getSessionStorageProvider()->preFillCheckoutParams($fill_params);
        }
    }

    // ===== ZEN MODE (существующая логика) =====
    // ...
}
```

---

## Edge Cases при переходе на посекционные флаги

### EC-1: Пользователь сменил оплату → доставка изменилась → оплата потеряна

**Сценарий:**

1. Plugin предзаполнил: оплата "Карта" (`payment._prefilled=1, payment.id=5`)
2. Пользователь меняет оплату на "Наличные" (`payment.id=7`)
3. Shop-Script сохраняет: `payment = {id: 7}` — `_prefilled` исчезает (Shop-Script перезаписывает массив)
4. Пользователь меняет тип доставки → Shop-Script стирает payment
5. Plugin видит: нет данных, нет `_prefilled` → предзаполняет `payment.id=5` ("Карта" из БД)
6. Предзаполненный `payment.id=5` несовместим с новым типом доставки → Shop-Script игнорирует → секция разворачивается, пользователь выбирает сам

**Оценка:** ✅ Не критично. Shop-Script сам покажет пустую развёрнутую секцию оплаты — пользователь выберет подходящий способ.

---

### EC-2: Пользователь удалил комментарий — plugin его восстанавливает

**Сценарий:**

1. Plugin предзаполнил комментарий из прошлого заказа (`confirm._prefilled=1, confirm.comment="текст"`)
2. Пользователь удаляет комментарий (очищает поле и сохраняет)
3. Shop-Script сохраняет секцию целиком — `_prefilled` исчезает:
   ```
   [confirm] => Array
       (
           [comment] =>
           [terms] => 1
           [html] => 1
           [timezone] => Asia/Novosibirsk
       )
   ```
4. Следующий запрос: нет `_prefilled`, `isSectionFilled('confirm')` проверяет `comment = ""` → `false`
5. Plugin видит "пустая секция" → предзаполняет старый комментарий ❌

**Оценка:** ⚠️ Известное ограничение. Shop-Script сохраняет `comment = ""` (ключ есть, значение пустое), но `isValueFilled("")` возвращает `false` — секция считается незаполненной.

**Mitigation:** Добавить в `SECTION_KEY_FIELDS['confirm']` поле `terms`. Ключ `terms` всегда присутствует после того как пользователь взаимодействовал с секцией, независимо от значения `comment`. Если `terms` есть → секция "тронута" → не перезаполняем.

---

### EC-3: Несовместимый payment.id после смены доставки

**Сценарий:**

1. Последний заказ пользователя: доставка "Почта России", оплата "Карта"
2. Текущий заказ: пользователь выбирает "Самовывоз"
3. Shop-Script стирает payment (доставка сменилась)
4. Plugin предзаполняет `payment.id = X` ("Карта" из БД)
5. Для "Самовывоза" "Карта" недоступна → Shop-Script игнорирует значение, секция разворачивается

**Оценка:** ✅ Не критично. Поведение идентично EC-1 — Shop-Script сам обработает несовместимый id.

---

### EC-4: Адрес доставки (details) при смене на самовывоз

**Сценарий:**

1. Plugin предзаполнил адрес: `details.shipping_address.street = "Ленина 1"`
2. Пользователь выбирает самовывоз
3. Секция `details` скрывается (не нужна для самовывоза) → не отправляется в POST → Shop-Script стирает
4. Plugin предзаполняет адрес снова (в скрытую секцию)
5. Пользователь переключается обратно на курьера → адрес уже предзаполнен ✅

**Оценка:** ✅ Нет проблем. Адрес молча восстанавливается в сессии — поведение ожидаемое.

---

### EC-5: `_prefilled` поле в данных — Shop-Script может его обработать

**Риск:** Shop-Script итерирует поля секций при рендеринге формы. Поле `_prefilled` может быть:

- Отправлено как hidden input в форму (утечка в POST)
- Воспринято как параметр плагина доставки/оплаты
- Вызвать ошибку при валидации

**Mitigation:** Необходимо протестировать. Если проблема подтвердится — хранить `_prefilled` в отдельном ключе `prefill_metadata` (но тогда теряем автоматическое стирание вместе с секцией).

---

### EC-6: Производительность — проверки на каждый запрос

**Текущее:** `first_prefill_done = true` → немедленный выход, 0 проверок.

**Новое:** Каждый запрос → `isSectionFilled()` для каждой секции → обращение к сессии.

**Оценка:** Незначительно. Данные уже в сессии (в памяти), нет SQL-запросов на этапе проверки. SQL выполняется только при фактическом предзаполнении (`getFillParams()`), которое само кешируется.

---

### EC-7: Порядок событий при первом входе

**Риск:** При первом заходе все секции пустые → plugin предзаполняет всё. Это ожидаемо. Но если `frontendHead` и `frontendOrder` вызываются в одном HTTP-запросе — двойное предзаполнение.

**Mitigation:** `$this->prefilled` (in-memory флаг на время HTTP-запроса) уже предотвращает это. Остаётся.

---

### EC-8: Несколько витрин

**Риск:** `checkout_params` в сессии — общие для всех витрин? Если да, `_prefilled` флаги витрины A влияют на витрину B.

**Mitigation:** Нужно проверить структуру `shop/checkout` в сессии — есть ли там разделение по витринам. Если нет — проблемы нет (каждая витрина имеет свои `order` данные).

---

## Итоговая таблица: что меняется

| Ситуация                                               | Текущее (global flag)               | С посекционными `_prefilled`       |
| ------------------------------------------------------ | ----------------------------------- | ---------------------------------- |
| Shop-Script стёр payment (Zen Mode)                    | ❌ Пустая секция навсегда           | ✅ Предзаполняется снова           |
| Shop-Script стёр payment (смена доставки)              | ❌ Пустая секция навсегда           | ✅ Предзаполняется, Shop-Script покажет выбор если несовместим |
| Пользователь сменил payment → потом доставка сменилась | ✅ Не перезаполняем (флаг защищает) | ✅ Предзаполняем, Shop-Script покажет выбор если несовместим  |
| Пользователь удалил комментарий                        | ✅ Не перезаполняем                 | ⚠️ Перезаполняем (см. EC-2)        |
| Первый вход на чекаут                                  | ✅ Заполняет один раз               | ✅ Заполняет один раз              |
| Повторный вход (всё заполнено)                         | ✅ Не трогает                       | ✅ Не трогает (секции filled)      |

## Открытый вопрос

**EC-2** (пользователь удалил комментарий) — единственный нерешённый кейс. Mitigation: добавить `terms` в `SECTION_KEY_FIELDS['confirm']` в `SectionChecker`. Нужно проверить, всегда ли `terms` присутствует в `confirm` после взаимодействия пользователя с формой.

---

## Детали обсуждения

- Первый разбор: conversation ID `0f0e825b-cf93-4e76-9ea2-470a3917ee7f` (2026-02-09)
- Новая проблема (payment при смене доставки): conversation ID `TBD` (2026-02-19)
