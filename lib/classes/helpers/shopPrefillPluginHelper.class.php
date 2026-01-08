<?php

class shopPrefillPluginHelper
{
    public static function deepMergeArrays(array &$array1, array &$array2): array
    {
        foreach ($array2 as $key => &$value) {
            if (is_array($value) && isset($array1[$key]) && is_array($array1[$key])) {
                self::deepMergeArrays($array1[$key], $value);
            } else {
                $array1[$key] = $value;
            }
        }

        return $array1;
    }

}
