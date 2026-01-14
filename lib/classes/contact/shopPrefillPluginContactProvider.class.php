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

        try {
            $contact = new waContact($contact_id);
            // Проверяем что контакт существует
            if (! $contact->exists()) {
                return null;
            }
            return $contact;
        } catch (waException $e) {
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
            if ($field instanceof waContactField && ! $field->getParameter('system')) {
                $result[] = $field_id;
            }
        }

        return $result;
    }
}
