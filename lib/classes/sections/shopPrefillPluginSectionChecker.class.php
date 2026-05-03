<?php

/**
 * Проверяет возможность предзаполнения секций checkout
 *
 * Использует положительную логику: группа включена = предзаполняем все её секции
 * Проверяет заполненность секции по ключевым полям через dot-notation
 */
class shopPrefillPluginSectionChecker
{
    private array $enabled_groups;

    /**
     * Маппинг секция → группа (совпадает с группами Zen Mode)
     */
    private const SECTION_TO_GROUP = [
        'auth'     => 'customer',
        'region'   => 'delivery',
        'shipping' => 'delivery',
        'details'  => 'delivery',
        'payment'  => 'payment',
        'confirm'  => 'confirm',
    ];

    /**
     * Ключевые поля для проверки заполненности секции (dot-notation)
     * Если хотя бы одно поле заполнено — секция считается предзаполненной
     */
    private const SECTION_KEY_FIELDS = [
        'auth'     => ['data.email', 'data.phone', 'data.firstname', 'html'],
        'region'   => ['city', 'html'],
        'shipping' => ['type_id'],
        'details'  => ['shipping_address.street', 'html'],
        'payment'  => ['id'],
        'confirm'  => ['comment', 'html'],
    ];

    public function __construct(array $enabled_groups)
    {
        $this->enabled_groups = $enabled_groups;
    }

    /**
     * Проверяет, включена ли группа для данной секции
     *
     * @param string $section_id ID секции (auth, region, shipping, details, payment, confirm)
     * @return bool true если группа секции включена
     */
    public function isGroupEnabledForSection(string $section_id): bool
    {
        $group = self::SECTION_TO_GROUP[$section_id] ?? null;
        if ($group === null) {
            return true;
        }
        $enabled = (bool)($this->enabled_groups[$group] ?? true);
        shopPrefillPluginLog::debug("Section group check: {$section_id} → {$group} " . ($enabled ? 'enabled' : 'disabled'));
        return $enabled;
    }

    /**
     * Проверяет можно ли предзаполнить секцию
     *
     * @param string $section_id ID секции (auth, region, shipping, details, payment, confirm)
     * @param array $checkout_params Текущие параметры checkout
     * @return bool true если можно предзаполнять
     */
    public function canPrefillSection(string $section_id, array $checkout_params): bool
    {
        // 1. Группа секции выключена в настройках → не предзаполняем
        if (!$this->isGroupEnabledForSection($section_id)) {
            shopPrefillPluginLog::debug("Section '{$section_id}' skipped: group disabled");
            return false;
        }

        // 2. Секция уже содержит ключевые данные → не перезаписываем
        if ($this->isSectionFilled($section_id, $checkout_params)) {
            shopPrefillPluginLog::debug("Section '{$section_id}' skipped: already filled");
            return false;
        }

        shopPrefillPluginLog::debug("Section '{$section_id}' can be prefilled");
        return true;
    }

    /**
     * Проверяет заполненность секции по ключевым полям
     *
     * @param string $section_id ID секции
     * @param array $checkout_params Параметры checkout
     * @return bool true если секция уже содержит данные
     */
    public function isSectionFilled(string $section_id, array $checkout_params): bool
    {
        $key_fields = self::SECTION_KEY_FIELDS[$section_id] ?? [];

        if (empty($key_fields)) {
            return false;
        }

        $section_data = $checkout_params['order'][$section_id] ?? [];

        // Если ЛЮБОЕ ключевое поле заполнено — секция "предзаполнена"
        foreach ($key_fields as $field_path) {
            $value = $this->getValueByPath($section_data, $field_path);
            if ($this->isValueFilled($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Получает значение по dot-notation пути
     *
     * @param array $data Массив данных
     * @param string $path Путь в формате 'key1.key2.key3'
     * @return mixed|null Значение или null если путь не найден
     */
    private function getValueByPath(array $data, string $path)
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!is_array($current) || !isset($current[$key])) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * Проверяет заполненность значения
     *
     * @param mixed $value Значение для проверки
     * @return bool true если значение заполнено
     */
    private function isValueFilled($value): bool
    {
        if ($value === null || $value === '' || $value === '0' || $value === 0) {
            return false;
        }
        return true;
    }
}
