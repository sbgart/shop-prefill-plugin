# Issue 31 — Security: слабый auth_token на основе MD5 без секрета

**Статус:** ⬜ Открыта  
**Приоритет:** 🔴 Критический  
**Сложность фикса:** 🔧 Небольшой  
**Файл:** `user/shopPrefillPluginUserProvider.class.php`, метод `getAuthToken`

## Проблема

Метод `getAuthToken()` генерирует токен авторизации из предсказуемых данных без серверного секрета:

```php
private function getAuthToken(): string
{
    $hash = md5($this->getCreateDatetime() . $this->getLogin() . $this->getPassword());
    return substr($hash, 0, 15) . $this->getId() . substr($hash, -15);
}
```

**Уязвимости:**

1. **MD5** — криптографически сломан, подвержен коллизиям
2. **Нет серверного секрета** — токен вычислим, если атакующий знает `create_datetime`, `login` и хеш пароля (все данные из БД)
3. **ID пользователя вшит в открытом виде** — упрощает перебор
4. **Нет привязки к сессии/IP** — токен валиден бессрочно с любого устройства

Если атакующий получит read-доступ к БД (SQL injection, бэкап, dump), он сможет вычислить auth_token любого пользователя и авторизоваться через cookie `auth_token`.

## Рекомендация

Использовать `hash_hmac` с серверным секретом и более стойким алгоритмом:

```php
private function getAuthToken(): string
{
    $payload = $this->getId() . '|' . $this->getCreateDatetime() . '|' . $this->getLogin();
    $secret  = wa()->getConfig()->getPath('config') . '/auth_secret';
    return hash_hmac('sha256', $payload, $secret);
}
```

Или делегировать генерацию токена в ядро Webasyst, если такой механизм уже существует (`waAuth::generateToken()`).
