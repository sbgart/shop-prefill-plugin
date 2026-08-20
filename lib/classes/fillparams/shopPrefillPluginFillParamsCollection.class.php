<?php

class shopPrefillPluginFillParamsCollection
{
    /** Значение настройки my_delivery_variants_limit по умолчанию */
    public const DEFAULT_LIMIT = 5;

    /** Верхняя граница: дальше диалог перестаёт быть выбором и становится списком */
    public const MAX_LIMIT = 10;

    private array $collection = [];

    /**
     * Приводит настройку «сколько вариантов показывать» к рабочему значению.
     *
     * Клампим на чтении, а не на записи: filter_var(FILTER_VALIDATE_INT) отдаёт false
     * на мусоре, а атрибуты min/max у <input type="number"> обходятся запросом мимо формы.
     *
     * @param mixed $value Сырое значение из настроек витрины
     */
    public static function normalizeLimit($value): int
    {
        if (! is_numeric($value)) {
            return self::DEFAULT_LIMIT;
        }

        $limit = (int) $value;

        // 0 и отрицательные — это «настройка не задана», а не «показывать ноль карточек»:
        // за полное отключение диалога отвечает отдельный флаг my_delivery_variants
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    public function get(): array
    {
        return $this->collection;
    }

    public function add(shopPrefillPluginFillParams $params): void
    {
        $this->collection[] = $params;
    }

    public function toArray(?int $limit = null): array
    {
        $result = [];

        $count = 0;
        foreach ($this->get() as $fill_params) {
            if ($limit !== null && $count >= $limit) {
                break; // Прекращаем добавление элементов, если достигнут лимит
            }
            $result[] = $fill_params->toArray();
            $count++;
        }

        return $result;
    }

}
