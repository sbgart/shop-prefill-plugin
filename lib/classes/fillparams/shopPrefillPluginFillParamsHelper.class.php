<?php

final class shopPrefillPluginFillParamsHelper
{
    public static function filteredOrderParams(array $params, string $prefix): array
    {
        if ($prefix === '') {
            return [];
        }

        $result     = [];
        $prefix_len = strlen($prefix);

        foreach ($params as $param => $value) {
            if (strpos($param, $prefix) === 0) {
                $result[substr($param, $prefix_len)] = ! empty($value) ? $value : null;
            }
        }

        return $result;
    }

    public static function removeDuplicateSubArrays(array $array, string $filter_key_prefix): array
    {
        $unique_array      = array();
        $serialized_arrays = array();

        // Входной массив должен быть отсортирован по ключу ASC (старые первые).
        // Итерируем в обратном порядке: первый встреченный для каждой сигнатуры — самый свежий.
        foreach (array_reverse($array, true) as $key => $sub_array) {
            $filtered_sub_array = array_filter(
                $sub_array,
                function ($k) use ($filter_key_prefix) {
                    return strpos($k, $filter_key_prefix) === 0;
                },
                ARRAY_FILTER_USE_KEY
            );

            $serialized = serialize($filtered_sub_array);

            if (! isset($serialized_arrays[$serialized])) {
                $serialized_arrays[$serialized] = true;
                $unique_array[$key]             = $sub_array;
            }
        }

        // Применяем array_reverse снова, чтобы восстановить исходный порядок элементов
        return array_reverse($unique_array, true);
    }


}
