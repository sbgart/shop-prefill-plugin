# Zen Mode — примеры значений переменных (`example`)

Список значений поля `example` из `getAvailableFields()` по обоим локалям.  
Источник данных: `locale/ru_RU` и `locale/en_US` + трассировка геттеров `shopPrefillCheckoutState` + `extractSummaryData()`.

> **Пометки:**  
> `[key]` — через `_wp('locale.key')`  
> `[hardcoded]` — литерал в `_wp()`, не ключ локали  
> ❌ — пример не совпадает с реальным выводом  
> ⚠️ — пример неточен или вводит в заблуждение

---

## Контакт (`group: contact`)

| Переменная | Реальный тип / источник | Пример ru_RU | Пример en_US | Статус |
|---|---|---|---|---|
| `$firstname` | `string` — `vars.auth.fields.firstname.value` | Иван | Ivan | ✅ |
| `$lastname` | `string` — `vars.auth.fields.lastname.value` | Иванов | Ivanov | ✅ |
| `$phone` | `string` — `vars.auth.fields.phone.value` | +7 (999) 123-45-67 | +1 234 567-8901 | ✅ |
| `$email` | `string` — `vars.auth.fields.email.value` | client@example.ru | customer@example.com | ✅ |
| `$company` | `string` — `vars.auth.fields.company.value` | ООО «Рога и копыта» | Acme LLC | ✅ |
| `$contact_custom` | `array<string, mixed>` — кастомные поля из `auth` | birthday: 15.03.1990 | birthday: 1990-03-15 | ✅ описательно |
| `$service_agreement` | `string` — `_wp('zen.service_agreement.yes/no')` или `''` | Согласен | Yes | ✅ |
| `$service_agreement_hint` | `string` — текст из конфига магазина (не локаль) | Я согласен на обработку персональных данных | I agree to the processing of personal data | ✅ описательно |

---

## Доставка (`group: delivery`)

| Переменная | Реальный тип / источник | Пример ru_RU | Пример en_US | Статус |
|---|---|---|---|---|
| `$shipping_name` | `string` — `selected_variant['name']` (название тарифа от плагина) | Курьер, 1–2 рабочих дня | Courier, 1–2 business days | ⚠️ см. ниже |
| `$shipping_rate` | **HTML** — `<span class="prefill-zen-price">...</span>` или `<span class="prefill-zen-price-free">Бесплатно</span>` | 300 ₽ | $5.00 | ❌ |
| `$delivery_method_name` | `string` — `getShippingMethods()[service_id]['name']` (имя из настроек магазина) | Доставка СДЭК | Express delivery | ✅ |
| `$shipping_logo` | `string\|null` — `selected_variant['logo'] ?: selected_variant['img'] ?: null` | https://cdn.example.ru/icons/delivery.png | https://cdn.example.com/icons/shipping.png | ✅ |
| `$delivery_plugin` | `string` — `selected_variant['plugin_name']` | Pickup point | Pickup point | ⚠️ hardcoded |
| `$delivery_tariff` | `string` — `selected_variant['service']` | Store pickup | Store pickup | ⚠️ hardcoded |
| `$delivery_type` | `string` — `formatDeliveryType(selected_variant['type'])` → локализованная строка | Самовывоз | *(пусто — msgstr "" в en_US)* | ⚠️ EN пуст |
| `$delivery_est_delivery` | `string` — `selected_variant['est_delivery']` | 1–2 дня | 1–2 days | ✅ |
| `$delivery_description` | `string` — `selected_variant['description']` или из `custom_data[*]['description']` | Доставка до двери | Delivery to your door | ✅ |
| `$delivery_schedule` | **HTML** — `selected_variant['pickup_schedule_html']` (генерируется ядром из расписания) | Пн–Пт 10:00–20:00 | Mon–Fri 10:00–20:00 | ❌ |
| `$delivery_way` | `string` — `custom_data[first]['way']` | Вход со двора | Entrance from the courtyard | ✅ |
| `$delivery_storage_days` | `string` — `(string) custom_data[first]['storage']['storage_days']` | 5 | 5 | ✅ |
| `$delivery_photos` | `array` — `custom_data[first]['photos']` (элементы: `uri`, `thumb_uri`) | Фото пункта выдачи (превью в ряд) | Pickup point photos (thumbnails in a row) | ✅ описательно |
| `$shipping_custom` | `array<string, mixed>` — `data.shipping.custom` | time_interval: 14:00–18:00 | time_interval: 14:00–18:00 | ✅ описательно |

---

## Адрес (`group: address`)

| Переменная | Реальный тип / источник | Пример ru_RU | Пример en_US | Статус |
|---|---|---|---|---|
| `$city` | `string` — `data.shipping.address.city` (первый непустой источник) | Москва | Springfield | ✅ |
| `$region` | `string` — `data.shipping.address.region` → `data.input.region.region` → `vars.region.selected_values.region_id` | 77 | CA | ⚠️ см. ниже |
| `$street` | `string` — `data.shipping.address.street` | ул. Ленина | Main St. | ✅ |
| `$building` | `string` — `data.shipping.address.building` | 10 | 10 | ✅ |
| `$apartment` | `string` — `data.shipping.address.apartment` | 15 | 15 | ✅ |
| `$zip` | `string` — `data.shipping.address.zip` | 123456 | 12345 | ✅ |
| `$address_custom` | `array<string, mixed>` — `data.shipping.address` минус стандартные поля | metro: Тверская | metro: Central | ✅ описательно |

---

## Оплата (`group: payment`)

| Переменная | Реальный тип / источник | Пример ru_RU | Пример en_US | Статус |
|---|---|---|---|---|
| `$payment_name` | `string` — `vars.payment.methods[id]['name']` | Наличными при получении | Cash on delivery | ✅ |
| `$payment_logo` | `string\|null` — `vars.payment.methods[id]['logo'] ?? ['img'] ?? null` | https://cdn.example.ru/icons/delivery.png | https://cdn.example.com/icons/shipping.png | ✅ |
| `$payment_description` | `string` — `vars.payment.methods[id]['description']` (текст из настроек) | Payment upon receipt | Payment upon receipt | ⚠️ hardcoded |
| `$payment_custom` | `array<string, mixed>` — `data.input.payment.custom` | inn: 7707083893 | inn: 7707083893 | ✅ описательно |

---

## Разбор несоответствий

### ❌ `$shipping_rate` — пример не отражает реальный вывод

**Что указано в примере:** `300 ₽` / `$5.00`

**Что реально хранится в переменной:** HTML-строка, сформированная `formatPrice()`:
```html
<span class="prefill-zen-price">300&nbsp;₽</span>
```
Или, если доставка бесплатная:
```html
<span class="prefill-zen-price-free">Бесплатно</span>
```

**Почему важно:** пользователь видит "300 ₽" и думает, что переменная — чистая строка. На практике она содержит HTML, который нельзя оборачивать в `|escape`, иначе теги вылезут наружу. Корректный шаблон: `{$shipping_rate}` (без модификаторов).

---

### ❌ `$delivery_schedule` — пример не отражает реальный вывод

**Что указано в примере:** `Пн–Пт 10:00–20:00` / `Mon–Fri 10:00–20:00`

**Что реально хранится в переменной:** HTML, сгенерированный ядром Shop-Script из расписания (`selected_variant['pickup_schedule_html']`). Обычно содержит разметку таблицы или списка:
```html
<ul class="schedule">
  <li><span>Пн–Пт</span> <span>10:00–20:00</span></li>
  <li><span>Сб</span> <span>11:00–18:00</span></li>
</ul>
```

**Почему важно:** аналогично `$shipping_rate` — HTML нельзя экранировать. Корректный шаблон: `{$delivery_schedule}`.

---

### ⚠️ `$region` — пример показывает код, а не имя

**Что указано в примере:** `77` (RU), `CA` (EN)

**Реальный приоритет источников в `getRegion()`:**
1. `data.shipping.address.region` — **имя региона**, заполняется ядром при выборе доставки (напр. "Московская область")
2. `data.input.region.region` — из формы, обычно тоже имя
3. `vars.region.selected_values.region_id` — **код** региона (напр. "77") — только как финальный fallback

**Вывод:** в большинстве сценариев переменная содержит имя региона, а не код. Примеры "77" и "CA" достижимы только если оба предыдущих источника пусты. Пример лучше заменить на "Московская область" / "California".

---

### ⚠️ `$shipping_name` — пример может вводить в заблуждение

**Что указано в примере:** `Курьер, 1–2 рабочих дня`

**Что в переменной:** `selected_variant['name']` — название тарифа, которое формирует плагин доставки. Большинство плагинов возвращают просто имя тарифа без срока: "Курьерская доставка", "Самовывоз". Срок доставки хранится отдельно в `$delivery_est_delivery`.

**Вывод:** пример смешивает два разных поля. Лучше заменить на просто "Курьер" / "Courier" чтобы не создавать впечатление, что `$shipping_name` всегда включает срок.

---

### ⚠️ Пустые переводы `$delivery_type` в en_US

`zen.delivery.type.pickup`, `zen.delivery.type.courier`, `zen.delivery.type.post` — `msgstr ""` в `en_US.po`. `formatDeliveryType()` вернёт пустую строку на английской локали.

---

### ⚠️ Hardcoded примеры (не через ключ локали)

| Поле | Значение `example` в коде | Реальный вывод |
|---|---|---|
| `$delivery_plugin` | `_wp('Pickup point')` | "Pickup point" (EN и RU — не переводится) |
| `$delivery_tariff` | `_wp('Store pickup')` | "Store pickup" (EN и RU — не переводится) |
| `$payment_description` | `_wp('Payment upon receipt')` | "Payment upon receipt" (EN и RU — не переводится) |
