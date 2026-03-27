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


}
