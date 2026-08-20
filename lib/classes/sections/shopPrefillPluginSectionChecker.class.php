<?php

/**
 * Проверяет возможность предзаполнения секций checkout
 *
 * Использует положительную логику: группа включена = предзаполняем все её секции
 * Проверяет заполненность секции по ключевым полям через dot-notation
 *
 * Важно: у секции два разных признака «непустоты», и путать их нельзя —
 * см. SECTION_OWNERSHIP_FIELDS и SECTION_DATA_FIELDS ниже.
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
     * Признак «секция принадлежит покупателю, писать в неё нельзя» (dot-notation).
     *
     * Отличается от SECTION_DATA_FIELDS ровно на служебный ключ `html`. Ядро кладёт его
     * в POST, когда просит вернуть HTML секции, а calculateAction() пишет весь POST в
     * сессию целиком — значит `html` появляется у секции сразу после первого рендера
     * в браузере. Это и используется как грубый маркер «покупатель уже держал секцию
     * в руках».
     *
     * `html` стоит ровно у четырёх секций со свободным вводом и отсутствует у двух, где
     * покупатель выбирает из готовых вариантов. Убрать его нельзя: без него prefill на
     * ближайшем calculate вернёт стёртые город, улицу и комментарий, и очистить поле
     * станет невозможно. См. docs/codereview/issue-59-html-key-marks-section-filled.md
     * и приоритет №1 в docs/concept/CONCEPT.md.
     */
    private const SECTION_OWNERSHIP_FIELDS = [
        'auth'     => ['data.email', 'data.phone', 'data.firstname', 'html'],
        'region'   => ['city', 'html'],
        'shipping' => ['type_id'],
        'details'  => ['shipping_address.street', 'html'],
        'payment'  => ['id'],
        'confirm'  => ['comment', 'html'],
    ];

    /**
     * Минимальный признак «группа заполнена» для дзен-режима: секции, без которых
     * сворачивать группу нельзя.
     *
     * Список намеренно короткий — в него входят только те секции, чьи данные
     * переживают короткое замыкание конвейера шагов (ошибка валидации выше по цепочке,
     * fast_render). `shipping` и `payment` его переживают, потому что JS-контроллер
     * checkout2 держит выбор у себя и отправляет его даже при пустом DOM. А `details`
     * сериализуется из формы, которая после такого рендера пуста, — и адрес пропадает
     * из сессии, хотя покупатель его не трогал. Поэтому `details` и `region` здесь нет.
     *
     * См. docs/bugs/zen-collapse-on-upstream-checkout-error.md, пункт 3.
     */
    private const GROUP_MINIMUM_SECTIONS = [
        'customer' => ['auth'],
        'delivery' => ['shipping'],
        'payment'  => ['payment'],
    ];

    /**
     * Признак «в секции есть реальные данные покупателя» (dot-notation).
     *
     * То же самое без `html`: флаг рендера — не данные. Отвечает на вопрос
     * «есть ли что показывать / что восстанавливать», а не «можно ли сюда писать».
     */
    private const SECTION_DATA_FIELDS = [
        'auth'     => ['data.email', 'data.phone', 'data.firstname'],
        'region'   => ['city'],
        'shipping' => ['type_id'],
        'details'  => ['shipping_address.street'],
        'payment'  => ['id'],
        'confirm'  => ['comment'],
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

        // 2. Секция принадлежит покупателю → не перезаписываем.
        // Намеренно ownership, а не data: секция со стёртым вручную полем данных не
        // содержит, но писать в неё всё равно нельзя — иначе поле не очистить.
        if ($this->isSectionOwnedByCustomer($section_id, $checkout_params)) {
            shopPrefillPluginLog::debug("Section '{$section_id}' skipped: belongs to customer");
            return false;
        }

        shopPrefillPluginLog::debug("Section '{$section_id}' can be prefilled");
        return true;
    }

    /**
     * Секция принадлежит покупателю: он её уже видел и мог править.
     * Предзаполнять такую секцию нельзя, даже если данных в ней сейчас нет.
     *
     * @param string $section_id ID секции
     * @param array $checkout_params Параметры checkout
     * @return bool
     */
    public function isSectionOwnedByCustomer(string $section_id, array $checkout_params): bool
    {
        return $this->matchesAnyField(
            self::SECTION_OWNERSHIP_FIELDS[$section_id] ?? [],
            $checkout_params['order'][$section_id] ?? []
        );
    }

    /**
     * В секции есть реальные данные покупателя.
     * Служебный ключ `html` за данные не считается.
     *
     * @param string $section_id ID секции
     * @param array $checkout_params Параметры checkout
     * @return bool
     */
    public function isSectionFilled(string $section_id, array $checkout_params): bool
    {
        return $this->matchesAnyField(
            self::SECTION_DATA_FIELDS[$section_id] ?? [],
            $checkout_params['order'][$section_id] ?? []
        );
    }

    /**
     * Заполнен ли минимум группы — то, без чего свёрнутый блок соврёт покупателю.
     *
     * @param string $group customer | delivery | payment
     * @param array $checkout_params Параметры checkout из сессии
     * @return bool true если группу можно сворачивать
     */
    public function isGroupMinimumFilled(string $group, array $checkout_params): bool
    {
        $sections = self::GROUP_MINIMUM_SECTIONS[$group] ?? null;
        if ($sections === null) {
            // Неизвестная группа: не наше дело её разворачивать
            return true;
        }

        foreach ($sections as $section_id) {
            if (!$this->isSectionFilled($section_id, $checkout_params)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Заполнено ли хотя бы одно поле из списка.
     *
     * @param array $field_paths Пути в dot-notation
     * @param mixed $section_data Данные секции
     * @return bool
     */
    private function matchesAnyField(array $field_paths, $section_data): bool
    {
        if (empty($field_paths) || !is_array($section_data)) {
            return false;
        }

        foreach ($field_paths as $field_path) {
            if ($this->isValueFilled($this->getValueByPath($section_data, $field_path))) {
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
