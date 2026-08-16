<?php

class shopPrefillPluginHelper
{
    public static function deepMergeArrays(array $base, array $override): array
    {
        $result = $base;
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = self::deepMergeArrays($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Рекурсивно убирает из массива листья null и '' (пустая строка).
     * '0', 0, false — валидные значения (например, кастомное поле адреса «этаж 0»), не трогаем.
     *
     * @param array $data
     * @return array
     */
    public static function stripEmptyLeaves(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = self::stripEmptyLeaves($value);
                if ($value !== []) {
                    $result[$key] = $value;
                }
            } elseif ($value !== null && $value !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

}
