<?php

return [
    'active'      => ['value' => false, 'filter' => FILTER_VALIDATE_BOOLEAN],
    'prefill'     => [
        'my_delivery_variants'                => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        'my_delivery_variants_button_classes' => ['value' => ''],
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
            // Продлевать уже выданный фреймворком auth_token (покупатель сам отметил «Запомнить меня»)
            'active'   => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
            // Выдавать auth_token после оформления заказа, где выбора у покупателя нет.
            // Opt-in: авторизация без явного согласия — решение магазина, не наше умолчание.
            'on_order' => ['value' => false, 'filter' => FILTER_VALIDATE_BOOLEAN],
            // 0 — стандартный срок Webasyst (30 дней), >0 — кастомный срок в днях
            'expires'  => ['value' => 0, 'filter' => FILTER_VALIDATE_INT],
        ],
        'guest'                               => [
            'enabled'          => ['value' => false, 'filter' => FILTER_VALIDATE_BOOLEAN], // Opt-in: гостевое предзаполнение хранит данные между визитами
            'consent_required' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN], // Требовать согласие гостя
        ],
    ],
    'styles'      => [
        'accent_color'      => ['value' => '#000'],
        'accent_color_dark' => ['value' => '#fff'],
        'custom_css'        => ['value' => ''],
    ],
    // Zen Mode — сворачивание секций чекаута
    'zen'         => [
        'active'                => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
        // 'default' | 'plugin' | 'none' — иконки в свёрнутых секциях: дефолтные, логотипы плагинов или без иконок
        'icon_display'          => ['value' => 'plugin'],
        // 'small' | 'medium' | 'large' — размер иконок (2.5rem×1.5rem, 3.5rem×2rem, 4.5rem×2.5rem)
        'icon_size'             => ['value' => 'medium'],
        'toggle_button_classes' => ['value' => ''],
        'groups'                => [
            'customer' => [
                'enabled'          => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
                // Скрытие заголовка auth: только при zen.active + свёртке группы «Покупатель» + флаг (см. FrontendHooks::isAuthHeaderHidden)
                'hide_auth_header' => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
                // 'default' | 'none' | 'custom' | 'avatar' — источник иконки для свёрнутого блока
                'icon_source'      => ['value' => 'default'],
                'icon'             => ['value' => ''],
                'summary_template' => ['value' => '{if $company}{$company} • {/if}{$firstname} {$lastname} • {$phone}'],
            ],
            'delivery' => [
                'enabled'          => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
                'icon'             => ['value' => ''],
                'icon_source'      => ['value' => 'default'], // 'default' | 'plugin' | 'custom'
                'summary_template' => ['value' => '<div class="wa-header">{$delivery_plugin}</div> <strong>{$shipping_name}</strong> • {$shipping_rate}{if $delivery_pickup_address}<br />{$delivery_pickup_address}{elseif $city || $street}<br />{$city}{if $street}, {$street}{/if}{if $building}, {$building}{/if}{if $apartment}, {$apartment}{/if}{/if}{if $delivery_est_delivery}<br /><strong>{$delivery_est_delivery}</strong>{/if}'],
                'custom_templates' => ['value' => []],
            ],
            'payment'  => [
                'enabled'          => ['value' => true, 'filter' => FILTER_VALIDATE_BOOLEAN],
                'icon'             => ['value' => ''],
                'icon_source'      => ['value' => 'default'], // 'default' | 'plugin' | 'custom'
                'summary_template' => ['value' => '<div class="wa-header">{$payment_name}</div>{$payment_description}'],
                'custom_templates' => ['value' => []],
            ],
        ],
    ],
    'update_time' => ['value' => 0, 'filter' => FILTER_VALIDATE_INT],
    'updated_by'  => ['value' => 0, 'filter' => FILTER_VALIDATE_INT],
];
