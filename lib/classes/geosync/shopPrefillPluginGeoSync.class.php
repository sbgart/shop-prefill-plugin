<?php

/**
 * Передаёт сторонним плагинам выбора города город из последнего заказа покупателя.
 *
 * Зачем: определение по IP ошибается при VPN, а прошлый заказ покупателя достовернее
 * любой геобазы. Подставив город раньше, чем стартует автоопределение, плагин не спорит
 * с ним, а отменяет его — окно «Ваш город?» не показывается, обращения к геобазе нет,
 * город в шапке совпадает с городом в корзине.
 *
 * Границы первого этапа интеграции:
 *
 *  - пишем только в чужие гео-хранилища; `shop/checkout` наш код не трогает, поэтому путь
 *    предзаполнения остаётся ровно таким же, каким был;
 *  - переходов между витринами не инициируем: если город их требует, не вмешиваемся вовсе;
 *  - подставляем не чаще раза за сессию. Это не экономия, а требование корректности:
 *    отпечаток адреса у эхо-кэша доставки — страна/регион/город/индекс, и повторная
 *    подстановка в середине сессии выбросила бы эхо, отняв у покупателя выбранный способ
 *    доставки.
 */
class shopPrefillPluginGeoSync
{
    /** Город свободен или лежит наша прошлая запись — подставляем */
    public const DECISION_APPLY = 'apply';

    /** Город сменил покупатель — отступаем и до конца сессии не трогаем (P1/P2) */
    public const DECISION_BACKOFF = 'backoff';

    /** Делать нечего: города нет либо он уже совпадает с нашим */
    public const DECISION_SKIP = 'skip';

    /** @var shopPrefillPluginGeoAdapterInterface[] */
    private array $adapters;

    private shopPrefillPluginGeoStorage $storage;
    private shopPrefillPluginFillParamsProvider $fill_params_provider;

    /** Одного прохода на запрос достаточно: `runController()` может быть вызван не раз */
    private bool $done = false;

    /**
     * @param shopPrefillPluginGeoAdapterInterface[] $adapters
     */
    public function __construct(
        array $adapters,
        shopPrefillPluginGeoStorage $storage,
        shopPrefillPluginFillParamsProvider $fill_params_provider
    ) {
        $this->adapters             = $adapters;
        $this->storage              = $storage;
        $this->fill_params_provider = $fill_params_provider;
    }

    /**
     * Чистое решение — вся логика правил G1 и G1a в одном месте, без обращений наружу.
     *
     * @param shopPrefillPluginGeoTarget      $current Что лежит у стороннего плагина сейчас
     * @param shopPrefillPluginGeoTarget|null $applied Что записали туда мы (null — не писали)
     * @param shopPrefillPluginGeoTarget      $target  Город из последнего заказа
     */
    public static function decide(
        shopPrefillPluginGeoTarget $current,
        ?shopPrefillPluginGeoTarget $applied,
        shopPrefillPluginGeoTarget $target
    ): string {
        if ($target->isEmpty()) {
            return self::DECISION_SKIP;
        }

        // Вакуум: города не выбирал никто, занимаем (G1)
        if ($current->isEmpty()) {
            return self::DECISION_APPLY;
        }

        // Первая встреча: город есть, но мы сюда не писали — отличить ручной выбор от
        // старого определения по IP невозможно, поэтому один раз перезаписываем (G1a)
        if ($applied === null) {
            return self::DECISION_APPLY;
        }

        // Лежит не то, что писали мы, — значит сменил человек
        if (! $current->equals($applied)) {
            return self::DECISION_BACKOFF;
        }

        // Наша запись и она же актуальна
        if ($current->equals($target)) {
            return self::DECISION_SKIP;
        }

        // Наша запись, но покупатель заказал в другой город
        return self::DECISION_APPLY;
    }

    /**
     * @throws waException
     */
    public function run(): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;

        $source_key = $this->fill_params_provider->getSourceKey();

        // Покупатель не опознан: истории нет по определению, и трогать ничего нельзя —
        // иначе просмотр каталога анонимом создаёт сессию и куки (P5)
        if ($source_key === null) {
            return;
        }

        $candidates = $this->collectCandidates();

        if (empty($candidates)) {
            return;
        }

        $target = $this->resolveTarget($source_key);

        if ($target->isEmpty()) {
            return;
        }

        foreach ($candidates as $candidate) {
            $this->applyTo($candidate['adapter'], $candidate['current'], $candidate['applied'], $target);
        }
    }

    /**
     * Кандидаты — доступные плагины, у которых покупатель не менял город сам.
     * Отсев делается по кукам и сессии, до и без обращения к базе.
     *
     * @return array<int, array{adapter: shopPrefillPluginGeoAdapterInterface, current: shopPrefillPluginGeoTarget, applied: ?shopPrefillPluginGeoTarget}>
     */
    private function collectCandidates(): array
    {
        $candidates = [];

        foreach ($this->adapters as $adapter) {
            if (! $adapter->isAvailable()) {
                continue;
            }

            $current = $adapter->getCurrent();
            $applied = $this->storage->getApplied($adapter->getId());

            if (! $current->isEmpty() && $applied !== null && ! $current->equals($applied)) {
                shopPrefillPluginLog::debug('Geo sync backs off: customer changed the city', [
                    'plugin' => $adapter->getId(),
                ]);
                continue;
            }

            $candidates[] = [
                'adapter' => $adapter,
                'current' => $current,
                'applied' => $applied,
            ];
        }

        return $candidates;
    }

    /**
     * Город берём в порядке удешевления: уже прочитанное в этой сессии → слепок прошлых
     * визитов в куке → и только в последнюю очередь запрос к базе.
     *
     * @throws waException
     */
    private function resolveTarget(string $source_key): shopPrefillPluginGeoTarget
    {
        if ($this->storage->getLoadedSource() === $source_key) {
            return $this->storage->getTarget();
        }

        $target = $this->storage->getTarget();

        if ($target->isEmpty()) {
            $target = shopPrefillPluginGeoTarget::fromFillParams($this->fill_params_provider->getFillParams());
        }

        if (! $target->isEmpty()) {
            $this->storage->rememberTarget($source_key, $target);
        }

        return $target;
    }

    private function applyTo(
        shopPrefillPluginGeoAdapterInterface $adapter,
        shopPrefillPluginGeoTarget $current,
        ?shopPrefillPluginGeoTarget $applied,
        shopPrefillPluginGeoTarget $target
    ): void {
        $decision = self::decide($current, $applied, $target);

        if ($decision !== self::DECISION_APPLY) {
            return;
        }

        // Стоп-условия первого этапа: подстановка потребовала бы перехода между витринами
        // либо не подействовала бы вовсе. Не вмешиваемся (B2a)
        if (! $adapter->canApply($target)) {
            return;
        }

        if ($adapter->apply($target)) {
            $this->storage->markApplied($adapter->getId(), $target);
        }
    }

    /**
     * Обновляет слепок городом только что оформленного заказа.
     *
     * Без этого покупатель, сменивший город в ходе оформления, навсегда расходится с нашей
     * записью, правило G1 уводит нас в отступление, и интеграция для него тихо умирает.
     * Данные здесь уже на руках — лишних запросов нет.
     */
    public function rememberOrderCity(shopPrefillPluginGeoTarget $target): void
    {
        if ($target->isEmpty()) {
            return;
        }

        $source_key = $this->fill_params_provider->getSourceKey();

        if ($source_key === null) {
            return;
        }

        $this->storage->rememberTarget($source_key, $target);

        foreach ($this->adapters as $adapter) {
            if (! $adapter->isAvailable()) {
                continue;
            }

            // Город заказа и есть то, что сейчас у стороннего плагина: покупатель либо
            // оставил наш, либо выбрал свой — в обоих случаях состояние сошлось
            if ($adapter->getCurrent()->equals($target)) {
                $this->storage->markApplied($adapter->getId(), $target);
            }
        }
    }

    /**
     * Отзыв согласия и очистка истории: стираем своё состояние и город, который подставили
     * сами. Город, выбранный покупателем вручную, не наш — его не трогаем.
     */
    public function forgetEverything(): void
    {
        foreach ($this->adapters as $adapter) {
            $applied = $this->storage->getApplied($adapter->getId());

            if ($applied === null || ! $adapter->isAvailable()) {
                continue;
            }

            $adapter->forget($applied);
        }

        $this->storage->clear();
    }
}
