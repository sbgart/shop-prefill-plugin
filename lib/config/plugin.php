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
        'frontend_order'           => "frontendOrder", //Предзаполняем форму только в корзине
        'frontend_head'            => 'frontendHead', //Предзаполняем форму при входе на сайт
        'checkout_render_auth'     => 'checkoutRenderAuth', //Добавляем контент в секцию авторизации
        'checkout_render_region'   => 'checkoutRenderRegion', //Добавляем контент в секцию региона
        'checkout_render_shipping' => 'checkoutRenderShipping', //Для сворачивания блоков корзины
        'checkout_render_confirm'  => 'checkoutRenderConfirm', //DEBUG: Показываем все delayed_errors
        'order_action.create'      => 'orderActionCreate', // Для сохранения shipping_type в параметры заказа
    ],
];
