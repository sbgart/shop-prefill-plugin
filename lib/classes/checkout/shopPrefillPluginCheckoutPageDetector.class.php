<?php

/**
 * Отвечает на единственный вопрос: рендерит ли текущий запрос форму оформления заказа.
 *
 * Нужен, чтобы CSS/JS плагина не висели на каталоге: вся его разметка живёт в форме
 * заказа, см. docs/codereview/issue-64-assets-loaded-on-every-page.md.
 *
 * Признака два, потому что по отдельности ни один не покрывает все темы:
 *
 * 1. Сработал любой checkout-хук плагина. Признак точный и не зависит от шаблонов темы
 *    (правило B3): `checkout_before_*` вызывает ядро из shopCheckoutStep::processAll().
 *    Успевает вовремя, потому что шаблон экшена рендерится до макета, а frontend_head
 *    срабатывает уже в макете. Ловит и нестандартный случай «форма вставлена
 *    в произвольную страницу витрины».
 *
 * 2. Маршрут страницы оформления заказа. Подстраховка для темы, которая рендерит форму
 *    из самого макета: там хуки сработают после frontend_head и признак 1 опоздает.
 *    Ассеты в этом случае лучше подключить лишний раз, чем оставить свёрнутый Zen-блок
 *    без работающей кнопки «Изменить» (правило Z3).
 */
class shopPrefillPluginCheckoutPageDetector
{
    /**
     * Маршрут страницы чекаута (checkout2) из routing.php приложения: 'order/' => 'frontend/order'.
     * Берём модуль с экшеном, а не URL, — магазин волен повесить чекаут на любой адрес.
     */
    public const CHECKOUT_ROUTE_MODULE = 'frontend';
    public const CHECKOUT_ROUTE_ACTION = 'order';

    /** @var callable(): array Возвращает [module, action] текущего маршрута */
    private $route_resolver;

    private bool $checkout_hook_fired = false;

    /**
     * @param callable $route_resolver Возвращает [module, action] текущего маршрута
     */
    public function __construct(callable $route_resolver)
    {
        $this->route_resolver = $route_resolver;
    }

    /**
     * Отмечает, что запрос пошёл по пути оформления заказа.
     * Вызывается из точки входа checkout-хуков — до того, как сработает frontend_head.
     */
    public function markCheckoutHookFired(): void
    {
        $this->checkout_hook_fired = true;
    }

    public function isCheckoutPage(): bool
    {
        return $this->checkout_hook_fired || $this->isCheckoutRoute();
    }

    /**
     * Маршрут читается лениво: на момент создания детектора роутинг мог ещё не отработать,
     * да и вне витрины параметров маршрута не существует вовсе.
     */
    private function isCheckoutRoute(): bool
    {
        $route = ($this->route_resolver)();

        $module = (string) ($route[0] ?? '');
        $action = (string) ($route[1] ?? '');

        return $module === self::CHECKOUT_ROUTE_MODULE && $action === self::CHECKOUT_ROUTE_ACTION;
    }
}
