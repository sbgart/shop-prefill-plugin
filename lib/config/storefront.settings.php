<?php

return [
    'active' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
    'prefill' => [
        'my_delivery_variants' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
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
        'remember_me' => [
            'active' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
            'expires' => ['value' => 90, 'filter' => FILTER_VALIDATE_INT], // 90 days
        ],
        'guest' => [
            'consent_required' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN], // Требовать согласие гостя
        ],
    ],
    'styles' => [
        'accent_color' => ['value' => '#000'],
    ],
    // Zen Mode — сворачивание секций чекаута
    'zen' => [
        'active' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        'hide_auth_header' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        'show_icons' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        'groups' => [
            'customer' => [
                'enabled' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
                'icon' => ['value' => ''],
                'summary_template' => ['value' => '{if $company}{$company} • {/if}{$firstname} {$lastname} • {$phone}'],
            ],
            'delivery' => [
                'enabled' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
                'icon' => ['value' => ''],
                'summary_template' => ['value' => '<strong>{$delivery_plugin}</strong><br />{$shipping_name} • {$shipping_rate}'],
                'custom_templates' => ['value' => []],
            ],
            'payment' => [
                'enabled' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
                'icon' => ['value' => ''],
                'summary_template' => ['value' => '<strong>{$payment_name}</strong><br />{$payment_description}'],
            ],
        ],
    ],
    'update_time' => ['value' => 0, 'filter' => FILTER_VALIDATE_INT],
    'updated_by' => ['value' => 0, 'filter' => FILTER_VALIDATE_INT],
];

