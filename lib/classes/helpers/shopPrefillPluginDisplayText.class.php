<?php

/**
 * Склейка текстов для витрины: части, которые нельзя печатать «как есть» из-за пустых и повторяющихся значений.
 *
 * Чистые функции без обращения к ядру — проверяются автотестом (tests/DisplayTextTest.php).
 */
class shopPrefillPluginDisplayText
{
    /**
     * Адрес одной строкой: пустые части пропускаются, а подряд идущие одинаковые схлопываются.
     *
     * Жёсткий шаблон «индекс, страна, регион, город, улица» даёт запятую в начале у самовывоза (нет индекса и улицы),
     * а у городов федерального значения регион совпадает с городом — «Москва, Москва».
     *
     * @param array $parts индекс, страна, регион, город, улица — в порядке вывода; не строки игнорируются
     */
    public static function joinAddress(array $parts): string
    {
        $result = [];
        $previous = null;
        foreach ($parts as $part) {
            if (!is_scalar($part)) {
                continue;
            }
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $key = self::normalize($part);
            if ($key === $previous) {
                continue;
            }
            $result[] = $part;
            $previous = $key;
        }

        return implode(', ', $result);
    }

    /**
     * Заголовок сводки доставки. По умолчанию это название службы, но у части плагинов (например, «Курьер по городу»)
     * название тарифа совпадает с названием службы, и сводка повторяет одну строку дважды. Тогда в заголовок
     * идёт тип доставки («Курьер», «Почта», «Самовывоз»): он не повторяет тариф и добавляет сведения.
     *
     * Если и тип совпал с тарифом или неизвестен, заголовку взять нечего, и он пустой: сводка показывает название
     * один раз. В шаблонах, сохранённых магазином, пустое значение оставляет пустой тег — это меньшее из зол
     * по сравнению с тремя одинаковыми словами подряд.
     */
    public static function deliveryHeader(string $plugin_name, string $tariff_name, string $type_label): string
    {
        $plugin_key = self::normalize($plugin_name);
        if ($plugin_key === '' || $plugin_key !== self::normalize($tariff_name)) {
            return $plugin_name;
        }

        $type_key = self::normalize($type_label);
        if ($type_key === '' || $type_key === $plugin_key) {
            return '';
        }

        return $type_label;
    }

    private static function normalize(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text));

        return mb_strtolower((string) $text, 'UTF-8');
    }
}
