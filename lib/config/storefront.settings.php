<?php

return [
    'active' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
    'prefill' => [
        'active' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        'on_entry' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        'source' => ['value' => 'last_order'],
        'default_payment' => ['value' => 0, 'filter' => FILTER_VALIDATE_INT],
        'sections' => [
            'auth' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
            'region' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
            'shipping' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
            'details' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
            'payment' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
            'confirm' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        ],
        'integration' => [
            'cityselect' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
            'dp' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        ],
    ],
    'remember_me' => [
        'active' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        'expires' => ['value' => 90, 'filter' => FILTER_VALIDATE_INT], // 90 days
    ],
    'guest' => [
        'consent_required' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN], // Требовать согласие гостя
    ],
    'styles' => [
        'accent_color' => ['value' => '#000'],
    ],
    'update_time' => ['value' => 0, 'filter' => FILTER_VALIDATE_INT],
    'updated_by' => ['value' => 0, 'filter' => FILTER_VALIDATE_INT],
];

