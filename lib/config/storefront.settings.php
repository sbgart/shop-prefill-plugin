<?php

return [
    'active'      => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
    'prefill'     => [
        'my_delivery_variants'                => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        'my_delivery_variants_button_classes' => ['value' => ''],
        'on_entry'                            => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        // Плавающая панель отладки и связанный UI (при глобальном debug Webasyst)
        'debug_panel'                         => ['value' => false, 'filter' => FILTER_VALIDATE_BOOLEAN],
        // Группы секций чекаута: customer=auth, delivery=region+shipping+details, payment, confirm
        'sections'                            => [
            'customer' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
            'delivery' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
            'payment'  => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
            'confirm'  => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        ],
        'integration'                         => [
            'cityselect' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
            'dp'         => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        ],
        'remember_me'                         => [
            'active'  => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
            'expires' => ['value' => 90, 'filter' => FILTER_VALIDATE_INT], // 90 days
        ],
        'guest'                               => [
            'consent_required' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN], // Требовать согласие гостя
        ],
    ],
    'styles'      => [
        'accent_color' => ['value' => '#000'],
    ],
    // Zen Mode — сворачивание секций чекаута
    'zen'         => [
        'active'                => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        'hide_auth_header'      => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        // 'default' | 'plugin' | 'none' — иконки в свёрнутых секциях: дефолтные, логотипы плагинов или без иконок
        'icon_display'          => ['value' => 'plugin'],
        // 'small' | 'medium' | 'large' — размер иконок (2.5rem×1.5rem, 3.5rem×2rem, 4.5rem×2.5rem)
        'icon_size'             => ['value' => 'medium'],
        'toggle_button_classes' => ['value' => ''],
        'groups'                => [
            'customer' => [
                'enabled'          => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
                'icon'             => ['value' => ''],
                'summary_template' => ['value' => '{if $company}{$company} • {/if}{$firstname} {$lastname} • {$phone}'],
            ],
            'delivery' => [
                'enabled'          => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
                'icon'             => ['value' => ''],
                'icon_source'      => ['value' => 'plugin'], // 'plugin' | 'default'
                'summary_template' => ['value' => '<strong>{$delivery_plugin}</strong><br />{$shipping_name} • {$shipping_rate}'],
                'custom_templates' => ['value' => []],
            ],
            'payment'  => [
                'enabled'          => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
                'icon'             => ['value' => ''],
                'icon_source'      => ['value' => 'plugin'], // 'plugin' | 'default'
                'summary_template' => ['value' => '<strong>{$payment_name}</strong><br />{$payment_description}'],
            ],
        ],
    ],
    'update_time' => ['value' => 0, 'filter' => FILTER_VALIDATE_INT],
    'updated_by'  => ['value' => 0, 'filter' => FILTER_VALIDATE_INT],
];

