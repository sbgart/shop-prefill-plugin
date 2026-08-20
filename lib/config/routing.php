<?php

// Суффикс `/?` делает завершающий слэш необязательным — так объявлены все frontend-роуты
// ядра Shop-Script (см. wa-apps/shop/lib/config/routing.php: 'api/v1/cart/?').
// Без него waRouting матчит якорно (waRouting.class.php:454), и обращение со слэшем
// возвращает 404, хотя без слэша тот же адрес работает.
return [
    'prefill/params-choice/?'        => 'frontend/ParamsChoice',
    'prefill/logs/?'                 => 'frontend/Logs',
    'prefill/clear-storage/?'        => 'frontend/ClearStorage',
    'prefill/force-prefill/?'        => 'frontend/ForcePrefill',
    'prefill/reset-and-refill/?'     => 'frontend/ResetAndRefill',
    'prefill/reset-snapshot/?'       => 'frontend/ResetSnapshot',
    'prefill/refresh-debug/?'        => 'frontend/RefreshDebug',
    'prefill/consent/?'              => 'frontend/Consent',
    'prefill/toggle-zen/?'           => 'frontend/ToggleZen',
    'prefill/apply-delivery/?'       => 'frontend/ApplyDelivery',
];
