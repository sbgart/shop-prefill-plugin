<?php

return [
    'name'            => "Предзаполнение полей оформления заказа",
    'description'     => "Упрощает оформление заказа в корзине.",
    'version'         => "1.0.0",
    'img'             => "img/plugin.png",
    'vendor'          => '1059969',
    'custom_settings' => true,
    'frontend'        => true,
    'handlers'        => [
        'frontend_order'      => "frontendOrder", //Предзаполняем форму только в корзине
        'frontend_head'       => 'frontendHead', //Предзаполняем форму при входе на сайт
        'checkout_result'     => 'checkoutRenderShipping', //Для сворачивания блоков корзины
        'order_action.create' => 'orderActionCreate', // Для сохранения shipping_type в параметры заказа
    ],
];