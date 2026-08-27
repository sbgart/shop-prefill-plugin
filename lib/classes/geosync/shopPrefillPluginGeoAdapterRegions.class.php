<?php

/**
 * Адаптер к плагину «SEO-регионы» (regions). Проверено на версии 3.2.10.
 *
 * Город у него — это витрина из URL плюс выбор в PHP-сессии
 * (`shop/plugins/regions/location_by_storefront`). Определение по IP ленивое и
 * запускается только из шаблона кнопки, пока пуст `confirmed_location`; выставив
 * подтверждение, мы гасим и окно «Ваш город?», и обращение к геобазе.
 *
 * Пишем теми же публичными сеттерами, которые зовёт их собственный `changeCity()`.
 * Сам `changeCity()` на первом этапе не вызываем: он безусловно заканчивается
 * редиректом, а переходы между витринами — задача этапа 4.
 *
 * Важно: `getCurrentCity()` для вакуума не годится — при пустой сессии он возвращает
 * дефолтный город витрины, и «никто не выбирал» стало бы неотличимо от «выбрали».
 * Поэтому вакуум определяем по самой сессионной локации.
 */
class shopPrefillPluginGeoAdapterRegions implements shopPrefillPluginGeoAdapterInterface
{
    public const ID = shopPrefillPluginGeoIntegrations::REGIONS;

    /** @var array<string, mixed> Настройки витрины */
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function isAvailable(): bool
    {
        // Тумблер имеет силу только вместе с установленным и включённым плагином
        if (! shopPrefillPluginGeoIntegrations::isEnabled($this->settings, self::ID)) {
            return false;
        }

        return $this->call(static function () {
            return class_exists('shopRegionsRouting')
                && class_exists('shopRegionsSessionStorage')
                && class_exists('shopRegionsStorefrontService')
                && class_exists('shopRegionsLocation');
        }, false);
    }

    public function getCurrent(): shopPrefillPluginGeoTarget
    {
        $empty = new shopPrefillPluginGeoTarget(null, null, null, null);

        return $this->call(function () use ($empty) {
            $storefront = $this->getStorefront();

            if ($storefront === null) {
                return $empty;
            }

            $location = shopRegionsSessionStorage::getInstance()->getLocation($storefront);

            // Пусто — город на этой витрине ещё никто не выбирал. Дефолтный город витрины
            // сюда не попадает намеренно: он не выбор, а настройка магазина.
            if (! $location) {
                return $empty;
            }

            $city = $this->resolveCityFromLocation($location);

            return $city === null ? $empty : $this->cityToTarget($city);
        }, $empty);
    }

    /**
     * Стоп-условия: города нет в базе плагина либо он привязан к другой витрине. Во втором
     * случае подстановка просто не подействовала бы — `getCurrentCity()` сверяет витрину
     * города с текущей и чужой отвергает, — а перевести покупателя на нужную витрину умеет
     * только `changeCity()` с редиректом. Для этого случая есть `propose()`.
     */
    public function canApply(shopPrefillPluginGeoTarget $target): bool
    {
        if ($target->isEmpty()) {
            return false;
        }

        return $this->findApplicableCity($target) !== null;
    }

    public function apply(shopPrefillPluginGeoTarget $target): bool
    {
        return $this->call(function () use ($target) {
            $storefront = $this->getStorefront();
            $city       = $this->findApplicableCity($target);

            if ($storefront === null || $city === null) {
                return false;
            }

            $session = shopRegionsSessionStorage::getInstance();

            // Виртуальный город (нет в базе плагина, собран из локации) хранится локацией,
            // зарегистрированный — идентификатором. Так же различает их сам changeCity().
            if (method_exists($city, 'isVirtual') && $city->isVirtual()) {
                $session->setLocation($storefront, ['location' => $city->getLocation()]);
            } else {
                $session->setLocation($storefront, ['city_id' => $city->getID()]);
            }

            $session->setConfirmedLocation(true);

            $this->reapplyRouteParams();

            shopPrefillPluginLog::info('City handed over to regions', [
                'city'       => $target->getCity(),
                'storefront' => $storefront,
            ]);

            return true;
        }, false);
    }

    /**
     * Кладёт наш город в слот «результат определения» плагина. Его окно после этого
     * показывается и называет наш город вместо догадки по IP, а переход выполняет его
     * собственный `changeCity()` — и только если покупатель нажмёт «Да» (правило G4).
     *
     * Побочно гасит их IP-детект на всю сессию: `getDetectedLocation()` сначала смотрит
     * в этот же ключ (`hasDetectedLocation()`), и внешнего запроса к геобазе не будет.
     */
    public function propose(shopPrefillPluginGeoTarget $target): bool
    {
        if ($target->isEmpty()) {
            return false;
        }

        return $this->call(function () use ($target) {
            $session = shopRegionsSessionStorage::getInstance();

            // Покупатель уже подтвердил город — их окно и так не показывается,
            // а предложение поверх подтверждения было бы спором с человеком (P1)
            if ($session->isConfirmedLocation()) {
                return false;
            }

            $location = $this->buildLocation($target);
            $city     = shopRegionsRouting::getInstance()->getCityByLocation($location);

            // Только зарегистрированный город. Слот читается через `getDetectedCity()`,
            // а тот при ненайденном городе падает в `getClosestCityByLocation()` — там
            // расстояние считается по `geo_lat`/`geo_lon`, которых у нашей локации нет,
            // и `null` превратился бы в точку (0,0): окно предложило бы случайный город.
            //
            // Виртуальный город (без ID) сюда не попадает по построению: его принимает
            // `findApplicableCity()`, то есть до `propose()` дело бы не дошло.
            if (! $city || ! $city->getID()) {
                return false;
            }

            $session->setDetectedLocation($location);

            shopPrefillPluginLog::info('City offered to regions as a storefront switch', [
                'city'       => $target->getCity(),
                'storefront' => $city->getStorefront(),
            ]);

            return true;
        }, false);
    }

    /**
     * Снимает предложение и возвращает плагину стоковое поведение.
     *
     * Через их `setDetectedLocation(null)` не выйдет: `hasDetectedLocation()` сравнивает
     * с `null` само значение ключа, а там оказалась бы строка `'N;'` — их IP-детект остался
     * бы подавлен до конца сессии. Метода удаления у них нет, поэтому убираем ключ напрямую;
     * имя проверено на 3.2.10 (`shopRegionsSessionStorage`).
     */
    public function forgetProposal(): void
    {
        $this->call(static function () {
            wa()->getStorage()->remove('shop/plugins/regions/detected_location');

            return null;
        }, null);
    }

    public function forget(shopPrefillPluginGeoTarget $applied): void
    {
        if (! $this->getCurrent()->equals($applied)) {
            return;
        }

        $this->call(function () {
            $storefront = $this->getStorefront();

            if ($storefront === null) {
                return null;
            }

            $session = shopRegionsSessionStorage::getInstance();
            // Пустая локация возвращает плагин к дефолтному городу витрины: отдельного
            // метода удаления у него нет, а `getLocation()` пустое значение считает за «нет».
            $session->setLocation($storefront, []);
            $session->setConfirmedLocation(false);

            shopPrefillPluginLog::info('Regions city dropped on consent revoke');

            return null;
        }, null);
    }

    /**
     * Город, который можно подставить на текущей витрине, либо null.
     *
     * @return object|null shopRegionsCity
     */
    private function findApplicableCity(shopPrefillPluginGeoTarget $target)
    {
        return $this->call(function () use ($target) {
            $storefront = $this->getStorefront();

            if ($storefront === null) {
                return null;
            }

            // Публичный метод плагина: сам разбирает точное совпадение, слияние и
            // виртуальный город по настройкам магазина
            $city = shopRegionsRouting::getInstance()->getCityByLocation($this->buildLocation($target));

            if (! $city) {
                return null;
            }

            // Виртуальный город к витрине не привязан и принимается любой
            if (! $city->getID()) {
                return $city;
            }

            return $city->getStorefront() === $storefront ? $city : null;
        }, null);
    }

    /**
     * Локация в терминах чужого плагина. Координаты не заполняем: в истории заказов их нет,
     * а нужны они только его режимам «ближайший город».
     *
     * @return object shopRegionsLocation
     */
    private function buildLocation(shopPrefillPluginGeoTarget $target)
    {
        return new shopRegionsLocation(
            $target->getCountry(),
            $target->getRegion(),
            $target->getCity(),
            null,
            null,
            $target->getZip()
        );
    }

    /**
     * @param array<string, mixed> $location
     * @return object|null shopRegionsCity
     */
    private function resolveCityFromLocation(array $location)
    {
        if (isset($location['city_id'])) {
            return shopRegionsCity::load($location['city_id']) ?: null;
        }

        if (isset($location['location'])) {
            return shopRegionsRouting::getInstance()->getCityByLocation($location['location']) ?: null;
        }

        return null;
    }

    /**
     * @param object $city shopRegionsCity
     */
    private function cityToTarget($city): shopPrefillPluginGeoTarget
    {
        return new shopPrefillPluginGeoTarget(
            $city->getCountryIso3(),
            $city->getRegionCode(),
            $city->getName(),
            $city->getZip()
        );
    }

    private function getStorefront(): ?string
    {
        $storefront = shopRegionsStorefrontService::getInstance()->getClosestStorefront();

        return is_string($storefront) && $storefront !== '' ? $storefront : null;
    }

    /**
     * Их обработчик на событии `routing` уже отработал со старым городом и выставил
     * `payment_id`/`shipping_id`/`stock_id` прежнего. Метод идемпотентный — зовём повторно,
     * чтобы параметры маршрута соответствовали подставленному городу уже в этом запросе.
     */
    private function reapplyRouteParams(): void
    {
        $this->call(static function () {
            if (class_exists('shopRegionsUpdateCurrentRouteParamsHandlerAction')) {
                (new shopRegionsUpdateCurrentRouteParamsHandlerAction())->execute(null);
            }

            return null;
        }, null);
    }

    /**
     * @param callable $fn
     * @param mixed    $fallback
     * @return mixed
     */
    private function call(callable $fn, $fallback)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            shopPrefillPluginLog::warning('Regions integration call failed', [
                'message' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }
}
