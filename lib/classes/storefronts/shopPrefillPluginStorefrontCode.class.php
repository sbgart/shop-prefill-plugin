<?php

/**
 * Код витрины — первичный ключ всех строк в shop_prefill_settings и имя per-storefront
 * CSS-файла на диске.
 *
 * Источник идентичности — `checkout_storefront_id` маршрута: непрозрачный md5, который ядро
 * выдаёт витрине один раз при создании (shopCheckoutConfig::generateStorefrontId(), с проверкой
 * уникальности по всем маршрутам) и больше не меняет. По нему же ядро хранит конфиг чекаута
 * (`$full_config[$storefront_id]` в shopCheckoutConfig::commit()), поэтому настройки плагина
 * живут и умирают вместе с настройками самого чекаута.
 *
 * Почему не адрес витрины: `url` маршрута ядро переписывает по действию администратора и молча —
 * «сделать главной страницей» (siteMainPage::setNewMainPage() ставит url='*', старый кладёт в
 * old_url, и вызывающий контроллер не шлёт ни одного события), переименование URL раздела,
 * переименование домена. Пока код считался как base64(domain/url), любое из этих действий
 * осиротевало все настройки витрины: строки оставались в базе под мёртвым кодом, витрина
 * откатывалась на глобальную, а при умолчании active=false плагин для неё выключался.
 * Разбор и протокол воспроизведения — docs/plans/storefront-identity-by-checkout-id.md.
 *
 * Класс намеренно без зависимостей — ни хелперов фреймворка, ни wa(): его должен грузить
 * автономный `php tests/StorefrontCodeTest.php`, как shopPrefillPluginOrphanedGroupsFilter.
 */
class shopPrefillPluginStorefrontCode
{
    /** Код глобальной витрины: настройки, общие для всех витрин */
    public const GLOBAL_CODE = '*';

    /**
     * @param string $domain Домен витрины ('*' у глобальной)
     * @param string $url    URL-шаблон маршрута ('*' у глобальной)
     * @param array  $route  Маршрут витрины целиком; у глобальной его нет
     */
    public static function fromRoute(string $domain, string $url, array $route = []): string
    {
        // Глобальная витрина маршрута не имеет — проверяем до обращения к нему
        if ($domain === self::GLOBAL_CODE && $url === self::GLOBAL_CODE) {
            return self::GLOBAL_CODE;
        }

        $id = trim((string) ($route['checkout_storefront_id'] ?? ''));

        if ($id !== '') {
            return $id;
        }

        // Маршруты, заведённые до checkout2, id не получают даже задним числом: routing_params
        // применяются только при создании маршрута (`if (!$route && ...)` в
        // siteConfigureSectionDialog.action.php и siteRoutingEdit.action.php). Для них остаётся
        // прежняя схема — иначе этот же фикс осиротил бы их настройки.
        return base64_encode($domain . '/' . $url);
    }
}
