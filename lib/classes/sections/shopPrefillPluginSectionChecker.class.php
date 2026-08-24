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
     * Признак «секция принадлежит покупателю, писать в неё нельзя» — служебный ключ `html`.
     *
     * Ядро кладёт `{section}[html]` в POST, когда просит вернуть HTML секции, а
     * `calculateAction()` пишет весь POST в сессию целиком — значит `html` появляется
     * у секции сразу после первого рендера в браузере и держится, пока жива сессия.
     * Это единственный сигнал владения, один и тот же для всех шести секций.
     *
     * До 22.08.2026 список опирался ещё и на содержимое (`city`, `type_id`...), но это
     * путало происхождение значения с его содержимым: система сама подставляет дефолтную
     * страну и единственный способ оплаты, а сторонние плагины (cityselect) пишут в
     * сессию до первого рендера чекаута — ни то, ни другое не значит «покупатель выбрал».
     * `html` от этого не зависит и не даёт открыть issue-65 заново.
     * См. docs/codereview/issue-65-prefill-overrides-current-input.md.
     *
     * Убрать совсем нельзя: без него prefill на ближайшем calculate вернёт стёртые город,
     * улицу и комментарий, и очистить поле станет невозможно.
     * См. docs/codereview/issue-59-html-key-marks-section-filled.md и P1/P2 в
     * docs/concept/RULES.md.
     */
    private const SECTION_OWNERSHIP_FIELDS = [
        'auth'     => ['html'],
        'region'   => ['html'],
        'shipping' => ['html'],
        'details'  => ['html'],
        'payment'  => ['html'],
        'confirm'  => ['html'],
    ];

    /**
     * Минимальный признак «группа заполнена» для дзен-режима: секции, без которых
     * сворачивать группу нельзя.
     *
     * Список намеренно короткий — в него входят только те секции, чьи данные
     * переживают короткое замыкание конвейера шагов (ошибка валидации выше по цепочке,
     * fast_render).
     *
     * Важно: сами по себе `shipping.variant_id` и `payment.id` короткое замыкание
     * НЕ переживают — ядро прячет секцию целиком, браузер сериализует пустоту, и
     * calculateAction() заменяет `order` этой пустотой. Устойчивость им даёт эхо-кэш
     * (SessionStorageProvider::syncDeliveryEcho/syncPaymentEcho), а не природа полей.
     * Прежняя формулировка утверждала обратное, опираясь на замер 19.08.2026, и была
     * опровергнута замером 24.08.2026.
     *
     * `details.shipping_address.street` в список не входит по другой причине: его
     * восстанавливает само ядро (`details_address`), но только когда доставка выбрана, —
     * как признак выбора он ложный.
     *
     * См. docs/bugs/shipping-payment-identity-lost-after-snapshot-removal.md
     */
    private const GROUP_MINIMUM_SECTIONS = [
        'customer' => ['auth'],
        'delivery' => ['shipping'],
        'payment'  => ['payment'],
    ];

    /**
     * Поля, которые секция рендерит в СВОЕЙ форме (ключи верхнего уровня).
     * Служебный `html` сюда не входит — его добавляет JS, а не разметка секции.
     *
     * Список необходим, потому что ядро кладёт в пространство имён одной секции поля,
     * отрисованные другой: `shipping[service_agreement]` живёт в форме **region**
     * (region.html:476-484). Секция region короткое замыкание переживает — она делает
     * работу в prepare(), — поэтому `order.shipping` в чекауте с включённым согласием
     * никогда не приходит «только с html», и проверка «секция ничего не прислала» по
     * составу всех ключей давала ложное «она говорила».
     *
     * Источники: auth.html, region.html, shipping.html, payment.html, confirm.html и
     * имена, собираемые шагами (shopCheckoutDetailsStep:144/210, PaymentStep:104/145,
     * shopCheckoutConfig:935).
     */
    private const SECTION_INPUT_FIELDS = [
        'auth'     => ['mode', 'user_id', 'data', 'service_agreement'],
        'region'   => ['country', 'region', 'city', 'city_id', 'zip', 'location_id'],
        'shipping' => ['type_id', 'variant_id'],
        'details'  => ['shipping_address', 'custom'],
        'payment'  => ['id', 'custom'],
        'confirm'  => ['comment', 'terms'],
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
        // Вариант — единственная идентичность выбора доставки (ядро выводит тип из
        // варианта, а не наоборот: shopCheckoutShippingStep:226-234, :253). Тип без
        // варианта — не выбор, а открытая вкладка; считать его данными нельзя (Z2).
        'shipping' => ['variant_id'],
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
     * Секция не прислала ни одного собственного поля — её не было на странице либо
     * ядро её не спрашивало. Пустота здесь ничего не говорит о намерении покупателя.
     *
     * Смотрим на присутствие собственных полей секции, а не на значение `html`: ядро
     * кодирует одно и то же «просто перерисуй меня» двумя разными значениями — `'only'`
     * у shipping и details (form.js:1871, :2429) и `1` у payment (form.js:2660).
     * Сравнение с `'only'` не срабатывало для payment при коротком замыкании и стирало
     * эхо-кэш ровно тогда, когда он был нужен.
     *
     * Почему именно собственные поля: отрисованная секция всегда шлёт хотя бы одно из
     * них — скрытые инпуты сериализуются даже пустыми (`shipping[type_id]` живёт в блоке
     * типов). Значит «покупатель не выбрал» приезжает как `{type_id:'', ...}`, а
     * отсутствие ключа целиком бывает только когда секции на странице нет. Это признак
     * отсутствия секции, а не признак пустоты, и путать их нельзя (P2).
     *
     * Считать «все ключи, кроме html» нельзя: в пространство имён секции попадают поля
     * чужих форм — см. SECTION_INPUT_FIELDS.
     *
     * См. docs/bugs/shipping-payment-identity-lost-after-snapshot-removal.md
     *
     * @param string $section_id ID секции
     * @param array $checkout_params Параметры checkout
     * @return bool
     */
    public function isSectionMechanicallyClean(string $section_id, array $checkout_params): bool
    {
        $section    = $checkout_params['order'][$section_id] ?? null;
        $own_fields = self::SECTION_INPUT_FIELDS[$section_id] ?? null;

        if (!is_array($section) || $own_fields === null) {
            return false;
        }

        // Важно наличие ключа, а не его значение: пустой скрытый инпут — тоже разговор
        return empty(array_intersect_key($section, array_flip($own_fields)));
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
