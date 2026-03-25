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

}
