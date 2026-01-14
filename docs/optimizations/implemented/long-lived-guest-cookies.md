# Хеш гостя для предзаполнения (prefill_guest_hash)

## Проблема

Ранее плагин хранил данные предзаполнения отдельно от заказов, что приводило к проблемам:

1. **Устаревшие данные** — менеджер исправил заказ, но данные предзаполнения не обновлялись
2. **Дублирование функционала** — Shop-Script уже восстанавливает брошенные корзины через localStorage
3. **Отсутствие привязки к заказам** — хранилось "последнее взаимодействие", а не данные из конкретного заказа

## Решение

Используем хранение хеша гостя в `shop_order_params`:

- **Для неавторизованных:** уникальный хеш в HTTP-only cookie → привязка к заказам в БД
- **Для авторизованных:** данные берутся напрямую по `contact_id` (хеш не нужен)

## Реализация

### shopPrefillPluginGuestHashStorage.class.php

```php
class shopPrefillPluginGuestHashStorage
{
    private const GUEST_HASH_PARAM = 'prefill_guest_hash';
    private const GUEST_HASH_COOKIE = 'prefill_guest_hash';
    private const COOKIE_TTL = 365 * 86400; // 1 год

    public function getOrCreateGuestHash(): string
    {
        $guest_hash = waRequest::cookie(self::GUEST_HASH_COOKIE, null, waRequest::TYPE_STRING);

        if ($guest_hash) {
            $this->setGuestHashCookie($guest_hash); // Продлеваем
            return $guest_hash;
        }

        // Генерируем новый хеш
        $unique_id  = 'guest_' . uniqid('', true) . '_' . microtime(true);
        $guest_hash = hash('sha256', $unique_id);

        $this->setGuestHashCookie($guest_hash);
        return $guest_hash;
    }

    private function setGuestHashCookie(string $hash): void
    {
        $this->response->setCookie(
            self::GUEST_HASH_COOKIE,
            $hash,
            time() + self::COOKIE_TTL,
            null, '', false,
            true  // HTTP-only — защита от XSS
        );
    }

    public function saveGuestHashToOrder(int $order_id, string $hash): bool
    {
        return $this->order_params_model->setOne($order_id, self::GUEST_HASH_PARAM, $hash);
    }
}
```

### shopPrefillPluginOrderProvider.class.php

```php
class shopPrefillPluginOrderProvider
{
    // Поиск заказов по contact_id (авторизованные)
    public function getLastOrderIdByContactId(int $contact_id): ?int { ... }
    public function getUserOrdersId(int $contact_id): ?array { ... }

    // Поиск заказов по guest_hash (неавторизованные)
    public function getLastOrderIdByGuestHash(string $hash): ?int
    {
        $result = $this->order_params_model->query(
            "SELECT order_id FROM shop_order_params
             WHERE name = s:name AND value = s:hash
             ORDER BY order_id DESC LIMIT 1",
            ['name' => shopPrefillPluginGuestHashStorage::getGuestHashParamName(), 'hash' => $hash]
        )->fetchField('order_id');

        return $result ? (int) $result : null;
    }

    public function getAllOrderIdsByGuestHash(string $hash): array
    {
        $results = $this->order_params_model->query(
            "SELECT order_id FROM shop_order_params
             WHERE name = s:name AND value = s:hash
             ORDER BY order_id DESC",
            ['name' => shopPrefillPluginGuestHashStorage::getGuestHashParamName(), 'hash' => $hash]
        )->fetchAll('order_id');

        return array_keys($results);
    }
}
```

## Механизм работы

```
Первый визит:
  1. Генерируем уникальный хеш → SHA256
  2. Сохраняем в HTTP-only cookie (1 год)

Оформление заказа (неавторизованный):
  1. Читаем хеш из cookie
  2. Сохраняем в shop_order_params: name='prefill_guest_hash', value='abc123...'

Повторный визит:
  1. Читаем хеш из cookie
  2. SELECT последний заказ с этим хешем
  3. Предзаполняем из данных заказа (актуальные!)
```

## SQL запросы

### Найти последний заказ гостя

```sql
SELECT order_id
FROM shop_order_params
WHERE name = 'prefill_guest_hash' AND value = '<hash>'
ORDER BY order_id DESC
LIMIT 1;
```

### Найти все заказы гостя

```sql
SELECT order_id
FROM shop_order_params
WHERE name = 'prefill_guest_hash' AND value = '<hash>'
ORDER BY order_id DESC;
```

## Преимущества

1. **Всегда актуальные данные** — берутся из БД
2. **Менеджер исправил заказ** → изменения сразу применяются
3. **Безопасность** — HTTP-only cookie защищает от XSS
4. **Можно показать список адресов** — все заказы гостя найдутся по хешу
5. **Производительность** — запрос с индексом по `name` и `value`

## Файлы изменены

- `lib/classes/fillparams/shopPrefillPluginGuestHashStorage.class.php` — управление хешем гостя (переименован из `FillParamsStorage`)
- `lib/classes/orders/shopPrefillPluginOrderProvider.class.php` — добавлены методы поиска заказов по guest_hash
- `lib/classes/fillparams/shopPrefillPluginFillParamsProvider.class.php` — использует OrderProvider для поиска заказов
- `lib/shopPrefill.plugin.php` — обновлены методы и зависимости
- `docs/concept/CONCEPT.md` — обновлена документация
