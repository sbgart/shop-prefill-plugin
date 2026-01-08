<?php

class shopPrefillPluginFillParamsHelper
{
    public static function filteredOrderParams(array $params, string $prefix): array
    {
        $result = [];

        foreach ($params as $param => $value) {
            $pos = strpos($param, $prefix);
            if ($pos !== false && $pos === 0) {
                $result[substr($param, strlen($prefix))] = !empty($value) ? $value : null;
            }
        }

        return $result;
    }

    public static function removeDuplicateSubArrays(array $array, string $filter_key_prefix): array
    {
        $unique_array = array();
        $serialized_arrays = array();

        // Итерируем по исходному массиву в обратном порядке, чтобы сохранить последние встретившиеся дубликаты
        foreach (array_reverse($array, true) as $key => $sub_array) {
            // Перенёс условие фильтрации в сам callback
            $filtered_sub_array = array_filter(
                $sub_array,
                function ($k) use ($filter_key_prefix) {
                    return strpos($k, $filter_key_prefix) === 0;
                },
                ARRAY_FILTER_USE_KEY
            );

            $serialized = serialize($filtered_sub_array);

            if (!isset($serialized_arrays[$serialized])) {
                $serialized_arrays[$serialized] = true;
                $unique_array[$key] = $sub_array; // Сохраняем подмассив при первом упоминании (поскольку идем с конца)
            }
        }

        // Применяем array_reverse снова, чтобы восстановить исходный порядок элементов
        return array_reverse($unique_array, true);
    }


}
