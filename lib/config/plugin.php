<?php
// Requires PHP >= 7.4 (arrow functions, typed properties, ??= operator)

return [
    'name' => "Предзаполнение полей оформления заказа",
    'description' => "Упрощает оформление заказа в корзине.",
    'version' => "1.0.0",
    'img' => "img/plugin.png",
    'vendor' => '1059969',
    'custom_settings' => true,
    'frontend' => true,
    'handlers' => [
        'frontend_head' => 'frontendHead', // Куки, ассеты, отладка. НЕ предзаполняет: см. docs/codereview/issue-63-*
        'checkout_before_auth' => 'checkoutBeforeAuth', // Предзаполняем при каждом AJAX-обновлении формы
        'checkout_render_auth' => 'checkoutRenderAuth', //Добавляем контент в секцию авторизации
        'checkout_render_region' => 'checkoutRenderRegion', //Добавляем контент в секцию региона
        'checkout_render_shipping' => 'checkoutRenderShipping', //Для сворачивания блоков корзины
        'checkout_render_details' => 'checkoutRenderDetails', //Добавляем контент в секцию details (Zen Mode для delivery)
        'checkout_render_payment' => 'checkoutRenderPayment', //Добавляем контент в секцию оплаты (Zen Mode)
        'checkout_render_confirm' => 'checkoutRenderConfirm', //DEBUG: Показываем все delayed_errors
        'order_action.create' => 'orderActionCreate', // shipping_type + гостевая ссылка prefill_guest_<lookup_id> в параметрах заказа
    ],
];
