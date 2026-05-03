<?php

return [
    'active'      => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
    'logging'     => [
        'level'   => ['value' => 'warning', 'filter' => FILTER_DEFAULT],
    ],
    'update_time' => ['value' => 0, 'filter' => FILTER_VALIDATE_INT],
    'updated_by'  => ['value' => 0, 'filter' => FILTER_VALIDATE_INT],
];

