<?php

/**
 * Провайдер для работы с контактами
 *
 * Инкапсулирует логику работы с waContact:
 * - Получение контакта по ID
 * - Извлечение полей auth (email, phone, кастомные поля)
 * - Определение типа покупателя (person/company)
 */
class shopPrefillPluginContactProvider
{
    /**
     * Кэш контактов на время запроса.
     *
     * Статический намеренно: waEvent пересоздаёт объект плагина на каждый хук (issue-73).
     * Гидратация коллекции вариантов запрашивает один и тот же contact_id по разу на карточку,
     * а waContact грузит поля лениво — без кэша это давало по пять запросов к wa_contact_data
     * на каждое поле формы (issue-68).
     *
     * @var array<int, waContact|null>
     */
    private static array $contacts = [];

    /** @var array<string, array> Кэш auth-полей контакта на время запроса, ключ — contact_id + набор полей */
    private static array $auth_data = [];

    /**
     * Получает контакт по ID
     *
     * @param int $contact_id ID контакта
     * @return waContact|null Контакт или null если не найден
     */
    public function getContact(int $contact_id): ?waContact
    {
        if ($contact_id <= 0) {
            return null;
        }

        if (array_key_exists($contact_id, self::$contacts)) {
            return self::$contacts[$contact_id];
        }

        return self::$contacts[$contact_id] = $this->loadContact($contact_id);
    }

    private function loadContact(int $contact_id): ?waContact
    {
        try {
            $contact = new waContact($contact_id);
            // Проверяем что контакт существует
            if (!$contact->exists()) {
                return null;
            }
            return $contact;
        } catch (waException $e) {
            shopPrefillPluginLog::warning('Failed loading contact in shopPrefillPluginContactProvider::getContact', [
                'contact_id' => $contact_id,
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Получает тип покупателя из контакта
     *
     * @param waContact $contact Контакт
     * @return string "person" или "company"
     */
    public function getCustomerType(waContact $contact): string
    {
        return $contact['is_company'] ? 'company' : 'person';
    }

    /**
     * Получает все поля auth[data] из контакта
     *
     * @param waContact $contact Контакт
     * @param array|null $field_ids Список ID полей для извлечения (null = все доступные)
     * @return array Ассоциативный массив [field_id => value]
     */
    public function getAuthData(waContact $contact, ?array $field_ids = null): array
    {
        // Пустые поля контакта ядро не кэширует: waContactStorage::get() пишет кэш только при
        // непустом результате, поэтому каждый повторный get('im'/'url'/…) снова идёт в БД.
        // Гидратация коллекции спрашивает один и тот же контакт по разу на карточку — считаем один раз.
        $cache_key = $contact->getId()
            ? $contact->getId() . '|' . ($field_ids === null ? '*' : implode(',', $field_ids))
            : null;

        if ($cache_key !== null && isset(self::$auth_data[$cache_key])) {
            return self::$auth_data[$cache_key];
        }

        $auth_data = [];

        // Если не указаны конкретные поля, получаем стандартные
        if ($field_ids === null) {
            $field_ids = $this->getDefaultAuthFieldIds($contact);
        }

        foreach ($field_ids as $field_id) {
            $value = $this->getContactFieldValue($contact, $field_id);
            if ($value !== null && $value !== '') {
                $auth_data[$field_id] = $value;
            }
        }

        if ($cache_key !== null) {
            self::$auth_data[$cache_key] = $auth_data;
        }

        return $auth_data;
    }

    /**
     * Получает значение поля контакта
     *
     * @param waContact $contact Контакт
     * @param string $field_id ID поля
     * @return string|null Значение поля или null
     */
    private function getContactFieldValue(waContact $contact, string $field_id): ?string
    {
        try {
            $value = $contact->get($field_id, 'default');

            if (is_array($value)) {
                // Для составных полей берем value или data
                $value = $value['value'] ?? $value['data'] ?? null;
            }

            return is_string($value) ? $value : null;
        } catch (waException $e) {
            shopPrefillPluginLog::warning('Failed getting contact field in shopPrefillPluginContactProvider::getContactFieldValue', [
                'field_id' => $field_id,
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Возвращает список стандартных полей auth для контакта
     *
     * @param waContact $contact Контакт
     * @return array Список ID полей
     */
    private function getDefaultAuthFieldIds(waContact $contact): array
    {
        $type = $contact['is_company'] ? 'company' : 'person';

        // Получаем все поля для данного типа контакта
        $all_fields = waContactFields::getAll($type);

        // Фильтруем только те, которые обычно используются в checkout
        $common_fields = ['email', 'phone', 'firstname', 'lastname', 'middlename', 'name', 'company'];

        $result = [];
        foreach ($all_fields as $field_id => $field) {
            // Добавляем стандартные поля
            if (in_array($field_id, $common_fields)) {
                $result[] = $field_id;
                continue;
            }

            // Добавляем кастомные поля (не системные)
            if ($field instanceof waContactField && !$field->getParameter('system')) {
                $result[] = $field_id;
            }
        }

        return $result;
    }
}
