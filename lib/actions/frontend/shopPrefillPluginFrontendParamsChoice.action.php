<?php

class shopPrefillPluginFrontendParamsChoiceAction extends waViewAction
{

    /**
     * @throws waException
     */
    public function execute()
    {
        $instance = shopPrefillPlugin::getInstance();
        $settings = $instance->getEffectiveStorefrontSettings();

        if (!$settings['active']) {
            return;
        }

        // История адресов доступна только после авторизации: гостевая cookie нужна
        // для автопредзаполнения последнего заказа, но не является учётной записью.
        if (!$instance->getUserProvider()->isAuth()
            || empty($settings['prefill']['my_delivery_variants'])
        ) {
            throw new waRightsException('Access denied');
        }

        // Сколько карточек показывать — решает владелец магазина (настройка витрины)
        $limit = shopPrefillPluginFillParamsCollection::normalizeLimit(
            $settings['prefill']['my_delivery_variants_limit'] ?? null
        );

        $fill_params_collection = $instance->getFillParamsProvider()->getFillParamsCollection($limit);
        $fill_params_array      = [];
        $items                  = $fill_params_collection->get();

        // Гарантируем, что при лимите остаются самые свежие (с максимальным order_id)
        usort($items, static function (shopPrefillPluginFillParams $left, shopPrefillPluginFillParams $right): int {
            return (int) $right->getId() <=> (int) $left->getId();
        });

        // Страховка: коллекция уже собрана под лимит, но экшен не полагается на это
        $items = array_slice($items, 0, $limit);

        // Определяем текущий сценарий доставки для подсветки активной карточки.
        //
        // Сессия shop/checkout ненадёжна, пока покупатель взаимодействует с формой —
        // как минимум тремя задокументированными способами (RULES.md, B2a): вымывание
        // при смене региона, обеднение при коротком замыкании валидации (это забирает
        // не только вариант доставки, но и адрес — улицу и т.п.) и рассогласованная
        // пара type_id/variant_id на цикл после смены типа доставки
        // (см. docs/bugs/params-choice-stale-highlight-after-type-switch.md).
        //
        // Вместо починки по одному полю за раз (что не масштабируется — плагин не может
        // предугадать каждое поле, которое ядро временно теряет) ParamsChoiceManager
        // перед открытием диалога сериализует ВСЮ форму через её же собственный контроллер
        // (window.waOrder Form.getFormData() — тем же методом, которым ядро само себя
        // готовит перед /order/calculate/) и шлёт как обычный POST. Ниже эта структура
        // передаётся в тот же getFillParamsByCheckoutParams(), что обычно разбирает
        // сессию, — просто источник другой. Любое поле, которое этот метод научится
        // читать в будущем, подхватится автоматически, без правок в этом экшене.
        $posted_order = waRequest::post();
        $has_form_snapshot = isset($posted_order['region'])
            || isset($posted_order['shipping'])
            || isset($posted_order['details'])
            || isset($posted_order['auth'])
            || isset($posted_order['payment'])
            || isset($posted_order['confirm']);

        $current = null;
        if ($has_form_snapshot) {
            try {
                $current = $instance->getFillParamsProvider()->getFillParamsByCheckoutParams(['order' => $posted_order]);
            } catch (TypeError $e) {
                // Форма прислала поле не той формы (например массив там, где ждём строку) —
                // не наш случай отлаживать здесь, откатываемся на сессию (B2a: при
                // неопределённости — стоковое поведение, а не падение с ошибкой).
                $current = null;
            }
        }

        // Не пришёл валидный снимок формы (старый JS-бандл, недоступен контроллер ядра
        // на странице, либо TypeError выше) — как и раньше, используем сессию.
        if ($current === null) {
            $checkout_params = $instance->getSessionStorageProvider()->getCheckoutParams();
            $current         = $instance->getFillParamsProvider()->getFillParamsByCheckoutParams($checkout_params);
        }

        foreach ($items as $item_obj) {
            $item_array               = $item_obj->toArray();
            $item_array['is_current'] = $item_obj->isSameDeliveryOption($current);
            $fill_params_array[]      = $item_array;
        }

        $this->view->assign([
            'app_id'            => shopPrefillPlugin::APP_ID,
            'plugin_id'         => shopPrefillPlugin::PLUGIN_ID,
            'plugin_url'        => shopPrefillPlugin::getStaticUrl(),
            'fill_params_array' => $fill_params_array,
        ]);
    }

}
