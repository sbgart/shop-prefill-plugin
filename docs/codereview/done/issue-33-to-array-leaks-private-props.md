# Issue 33 — `toArray()` утечка приватных свойств через `get_object_vars`

**Статус:** ✅ Закрыта (как неприменимая в заявленной формулировке)  
**Приоритет:** 🟡 Средний  
**Сложность фикса:** 🔧 Небольшой  
**Файл:** `fillparams/shopPrefillPluginFillParams.class.php`, метод `toArray`

## Результат проверки

Подтверждено, что `toArray()` через `get_object_vars($this)` включает private-свойства и в ответ попадают служебные ключи (`*_params`, `active`).

При этом пункт «ломает `isSameDeliveryOption`» не воспроизводится: метод сравнивает значения только по именам полей из whitelist (`region_params + shipping_params`) и не использует результат `toArray()`.

Итог: issue в текущем виде (как functional bug) закрыта как неприменимая. Это скорее вопрос чистоты контракта ответа, а не поломки логики сравнения.

## Что можно сделать позже (опционально)

Если понадобится ужесточить контракт API, в `toArray()` можно исключить служебные ключи (`region_params`, `auth_params`, `contact_params`, `payment_params`, `shipping_params`) или перейти на явный whitelist полей.
