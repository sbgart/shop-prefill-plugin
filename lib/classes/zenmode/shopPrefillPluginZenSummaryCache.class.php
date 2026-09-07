<?php

/**
 * Кэш данных сводки дзен-режима.
 *
 * Зачем. Сводка показывает названия и цены — «Бесплатная доставка курьером», «Бесплатно»,
 * логотип плагина. Их вычисляют плагины доставки и оплаты внутри shopCheckoutStep::process(),
 * то есть они существуют только в $params текущего рендера. А $params пуст для всех секций
 * ниже упавшего шага (ошибка валидации) и при fast_render — на каждой загрузке чекаута.
 * В сессии на этот случай лежат только идентификаторы, названий там нет.
 *
 * Поэтому последний удачный набор данных сводки складывается сюда и подставляется,
 * когда в рендере данных нет. Решение «сворачивать или нет» кэш не принимает — оно
 * считается по сессии, см. shopPrefillPluginZenMode::shouldCollapseGroup().
 *
 * Хранится массив, а не готовый HTML: смена шаблона или настроек витрины применяется
 * сразу, и разметка не оседает в сессии.
 *
 * Запись принадлежит личности, а не сессии. Сессия переживает смену личности целиком:
 * PHPSESSID при логауте и логине не меняется, и без штампа владельца следующая личность
 * видела в свёрнутой карточке данные предыдущей — при пустом $params кэш подставлялся
 * не спрашивая, чей он. Поэтому рядом с данными лежит отпечаток источника
 * (shopPrefillPluginFillParamsProvider::getSourceKey()), и чужая запись читается как пустая.
 * Тот же приём уже применён в shopPrefillPluginGeoStorage.
 *
 * См. docs/bugs/zen-collapse-on-upstream-checkout-error.md, пункт 2,
 * docs/bugs/zen-summary-cache-leaks-across-identity-change.md и
 * docs/plans/zen-summary-cache-identity-scope.md.
 */
class shopPrefillPluginZenSummaryCache
{
    private const STORAGE_KEY = 'shop/prefill_zen_summary';

    /**
     * Владелец, с которым не совпадёт ни один настоящий отпечаток источника
     * ('user:…', 'guest:…' либо null). Ставится, когда отпечаток не удалось вычислить:
     * при неопределённости кэш обязан промахнуться, а не подставить непонятно чьё (B2a).
     */
    private const OWNER_UNKNOWN = "\0unknown";

    /**
     * Группа дзен-режима → группы полей в shopPrefillPluginZenData::getAvailableFields().
     * Кэшируем только поля своей группы, чтобы устаревшее значение одной группы
     * не подмешалось в шаблон другой.
     */
    private const GROUP_FIELDS = [
        'customer' => ['contact'],
        'delivery' => ['delivery', 'address'],
        'payment'  => ['payment'],
    ];

    /**
     * Поля, по которым видно, что рендер реально принёс данные группы.
     *
     * Для delivery это именно название способа доставки: адресные поля приходят из шага
     * region, который рендерится даже при коротком замыкании, и по ним группа ошибочно
     * выглядела бы наполненной (та самая сводка «·» с одним адресом).
     */
    private const PRESENCE_FIELDS = [
        'customer' => ['firstname', 'lastname', 'phone', 'email'],
        'delivery' => ['shipping_name'],
        'payment'  => ['payment_name'],
    ];

    private waSessionStorage $storage;

    private shopPrefillPluginFillParamsProvider $fill_params_provider;

    /** @var array<string, array<string, string>>|null Кэш имён полей по группам */
    private static ?array $field_names = null;

    public function __construct(
        waSessionStorage $storage,
        shopPrefillPluginFillParamsProvider $fill_params_provider
    ) {
        $this->storage              = $storage;
        $this->fill_params_provider = $fill_params_provider;
    }

    /**
     * Принёс ли текущий рендер данные группы.
     *
     * @param string $group Имя группы
     * @param array $data Результат extractSummaryData()
     * @return bool
     */
    public function hasFreshData(string $group, array $data): bool
    {
        foreach (self::PRESENCE_FIELDS[$group] ?? [] as $field) {
            $value = $data[$field] ?? '';
            if (is_array($value) ? !empty($value) : trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Запоминает поля группы из удачного рендера.
     *
     * @param string $group Имя группы
     * @param array $data Результат extractSummaryData()
     */
    public function set(string $group, array $data): void
    {
        $fields = $this->fieldsOf($group);
        if (empty($fields)) {
            return;
        }

        try {
            // readAll() уже вернул пустоту, если запись была чужой, — так данные прежней
            // личности вытесняются первой же записью текущей, а не копятся в сессии
            $groups = $this->readAll();
            $groups[$group] = array_intersect_key($data, array_flip($fields));

            $this->storage->set(self::STORAGE_KEY, [
                'owner'  => $this->currentOwner(),
                'groups' => $groups,
            ]);
        } catch (waException $e) {
            shopPrefillPluginLog::warning('Failed saving zen summary cache', [
                'group'   => $group,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Возвращает запомненные поля группы.
     *
     * @param string $group Имя группы
     * @return array Пустой массив, если запоминать было нечего
     */
    public function get(string $group): array
    {
        $cached = $this->readAll()[$group] ?? null;

        return is_array($cached) ? $cached : [];
    }

    /**
     * Сбрасывает кэш всех групп. Вызывается вместе с очисткой cookies после заказа.
     */
    public function clear(): void
    {
        $this->storage->remove(self::STORAGE_KEY);
    }

    /**
     * Имена полей, принадлежащих группе.
     *
     * @param string $group Имя группы
     * @return string[]
     */
    private function fieldsOf(string $group): array
    {
        if (self::$field_names === null) {
            $by_group = [];
            foreach (shopPrefillPluginZenData::getAvailableFields() as $field => $meta) {
                $by_group[$meta['group'] ?? ''][] = $field;
            }
            self::$field_names = $by_group;
        }

        $result = [];
        foreach (self::GROUP_FIELDS[$group] ?? [] as $field_group) {
            $result = array_merge($result, self::$field_names[$field_group] ?? []);
        }

        return $result;
    }

    /**
     * Данные всех групп, если запись принадлежит текущей личности.
     *
     * Промах вместо чужих данных — это деградация в уже принятое состояние «первая отрисовка
     * первого визита» (кэш пуст, $params пуст из-за fast_render), см. отвергнутый вариант 6
     * в docs/bugs/zen-collapse-on-upstream-checkout-error.md.
     *
     * @return array<string, array>
     */
    private function readAll(): array
    {
        $all = $this->storage->get(self::STORAGE_KEY);

        if (! is_array($all)) {
            return [];
        }

        // Записи до появления штампа (старый плоский формат) владельца не имеют. Отличать их
        // от легитимного владельца null (гость без куки) умеет только array_key_exists
        if (! array_key_exists('owner', $all) || $all['owner'] !== $this->currentOwner()) {
            return [];
        }

        return is_array($all['groups'] ?? null) ? $all['groups'] : [];
    }

    /**
     * Отпечаток текущей личности: 'user:<id>', 'guest:<lookup_id>' либо null у гостя без куки.
     * Запросов к БД не делает и куки не выдаёт — только читает уже имеющиеся (P5, P8).
     *
     * @return string|null
     */
    private function currentOwner(): ?string
    {
        try {
            return $this->fill_params_provider->getSourceKey();
        } catch (Throwable $e) {
            shopPrefillPluginLog::warning('Failed resolving zen summary cache owner', [
                'message' => $e->getMessage(),
            ]);

            return self::OWNER_UNKNOWN;
        }
    }
}
