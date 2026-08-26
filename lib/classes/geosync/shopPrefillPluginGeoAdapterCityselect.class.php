<?php

/**
 * Адаптер к плагину «Автоопределение и выбор города» (cityselect, Echo-company).
 * Проверено на версии 2.0.4.
 *
 * Город у него хранится в куках `cityselect__*`, и его собственный `detectLocation()`
 * читает их первыми, комментируя это прямым текстом: «куки это то что пользователь
 * выбрал вручную». Поэтому заполненная кука не спорит с определением по IP, а отменяет
 * его: флаг `need_detect` не выставляется, скрипт `shop_cityselect.detect()` в разметку
 * не выводится, окно «Ваш город?» не показывается, обращения к DaData нет.
 *
 * Пишем не в куки напрямую, а их публичной функцией `shopCityselectHelper::setCity()` —
 * той самой, которую зовут оба их контроллера. Её побочные эффекты (для авторизованного
 * `$contact->save()`, запись `order.region` в сессию чекаута) приняты осознанно: плагин
 * делает это при любой смене города покупателем, новый здесь только повод.
 */
class shopPrefillPluginGeoAdapterCityselect implements shopPrefillPluginGeoAdapterInterface
{
    public const ID = 'cityselect';

    private const PLUGIN_ID = 'cityselect';
    private const DP_PLUGIN_ID = 'dp';

    /** Кука «уведомление уже показывали»: гасит режим «выборочно» в getNotifierType() */
    private const NOTIFIER_SHOWN_COOKIE = 'cityselect__show_notifier';

    private const COOKIE_TTL = 12 * 30 * 86400;

    /** @var array<string, mixed> Настройки витрины */
    private array $settings;

    private waResponse $response;

    /**
     * @param array<string, mixed> $settings Настройки эффективной витрины
     */
    public function __construct(array $settings, waResponse $response)
    {
        $this->settings = $settings;
        $this->response = $response;
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function isAvailable(): bool
    {
        if (empty($this->settings['prefill']['integration'][self::ID])) {
            return false;
        }

        if (! shopPrefillPlugin::enableInstall(self::PLUGIN_ID)) {
            return false;
        }

        // Плагин может быть установлен, но выключен на этой витрине собственной настройкой
        return $this->call(static function () {
            return class_exists('shopCityselectPlugin') && shopCityselectPlugin::isEnable();
        }, false);
    }

    public function getCurrent(): shopPrefillPluginGeoTarget
    {
        return new shopPrefillPluginGeoTarget(
            waRequest::cookie('cityselect__country', '', waRequest::TYPE_STRING_TRIM),
            waRequest::cookie('cityselect__region', '', waRequest::TYPE_STRING_TRIM),
            waRequest::cookie('cityselect__city', '', waRequest::TYPE_STRING_TRIM),
            waRequest::cookie('cityselect__zip', '', waRequest::TYPE_STRING_TRIM)
        );
    }

    /**
     * Стоп-условие первого этапа: администратор настроил для этого города переход на другую
     * витрину. Подставить город и не выполнить переход значило бы оставить магазин в
     * состоянии, против которого он настраивался, а выполнять переход — задача этапа 4.
     * Молча не вмешиваемся (B2a).
     *
     * Заодно это снимает риск от их публичного `checkRedirect()`: в 2.0.4 его никто не
     * вызывает, но тема покупателя вправе позвать сама, и тогда наша кука стала бы
     * триггером перехода на обычном просмотре каталога.
     */
    public function canApply(shopPrefillPluginGeoTarget $target): bool
    {
        if ($target->isEmpty()) {
            return false;
        }

        $redirect = $this->call(static function () use ($target) {
            if (! class_exists('shopCityselectHelper')) {
                return '';
            }

            return (string) shopCityselectHelper::detectRedirect([
                'region' => $target->getRegion(),
                'city'   => $target->getCity(),
            ]);
        }, '');

        if ($redirect !== '') {
            shopPrefillPluginLog::debug('Cityselect: skipping, storefront redirect is configured for this city', [
                'city'     => $target->getCity(),
                'redirect' => $redirect,
            ]);
            return false;
        }

        return true;
    }

    public function apply(shopPrefillPluginGeoTarget $target): bool
    {
        $applied = $this->call(static function () use ($target) {
            shopCityselectHelper::setCity(
                $target->getCity(),
                $target->getRegion(),
                $target->getZip(),
                $target->getCountry()
            );

            return true;
        }, false);

        if (! $applied) {
            return false;
        }

        // Второй путь к всплывашке — режим «выборочно»: она форсится, пока эта кука пуста
        $this->setCookie(self::NOTIFIER_SHOWN_COOKIE, (string) time());

        $this->dropStaleKladrCookies();

        $this->applyDeliveryPaymentPlugin($target);

        shopPrefillPluginLog::info('City handed over to cityselect', ['city' => $target->getCity()]);

        return true;
    }

    public function forget(shopPrefillPluginGeoTarget $applied): void
    {
        if (! $this->getCurrent()->equals($applied)) {
            // Город сменил покупатель — он не наш, стирать чужое не имеем права
            return;
        }

        foreach (['country', 'region', 'city', 'zip'] as $field) {
            $this->expireCookie('cityselect__' . $field);
        }

        $this->expireCookie(self::NOTIFIER_SHOWN_COOKIE);
        $this->dropStaleKladrCookies();

        shopPrefillPluginLog::info('Cityselect city dropped on consent revoke');
    }

    /**
     * Гасит куки КЛАДР/ФИАС, оставшиеся от прошлого города.
     *
     * Их выставляет только контроллер `set_city` из ответа DaData, а у нас в истории заказов
     * этих идентификаторов нет — `setCity()` их не трогает. Оставить чужие значит ограничить
     * автодополнение улицы прошлым городом: в `initStreet()` непустой `constraints_street`
     * имеет приоритет над названием города, и покупателю в Новосибирске предлагались бы
     * московские улицы. Пустые куки возвращают плагин к ограничению по названию города и
     * коду региона — ровно то, что у нас есть.
     */
    private function dropStaleKladrCookies(): void
    {
        foreach (['cityselect__kladr_id', 'cityselect__fias_id', 'cityselect__constraints_street'] as $name) {
            if (waRequest::cookie($name, '', waRequest::TYPE_STRING_TRIM) !== '') {
                $this->expireCookie($name);
            }
        }
    }

    /**
     * Плагин «Доставка и платежи» держит собственный набор кук. cityselect пишет их сам,
     * когда включена его настройка `plugin_dp`; мы дублируем ту же запись, если магазин
     * разрешил интеграцию и плагин установлен.
     */
    private function applyDeliveryPaymentPlugin(shopPrefillPluginGeoTarget $target): void
    {
        if (empty($this->settings['prefill']['integration'][self::DP_PLUGIN_ID])) {
            return;
        }

        if (! shopPrefillPlugin::enableInstall(self::DP_PLUGIN_ID)) {
            return;
        }

        $this->setCookie('dp_plugin_country', $target->getCountry());
        $this->setCookie('dp_plugin_region', $target->getRegion());
        $this->setCookie('dp_plugin_city', $target->getCity());
        $this->setCookie('dp_plugin_zip', $target->getZip());
    }

    private function setCookie(string $name, string $value): void
    {
        $this->response->setCookie($name, $value, time() + self::COOKIE_TTL);
    }

    private function expireCookie(string $name): void
    {
        $this->response->setCookie($name, '', time() - 3600);
    }

    /**
     * Любое обращение к чужому коду — через эту обёртку: чужой плагин может быть другой
     * версии, а `waEvent` ловит только `Exception` и пропустил бы `TypeError` наружу.
     *
     * @param callable $fn
     * @param mixed    $fallback
     * @return mixed
     */
    private function call(callable $fn, $fallback)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            shopPrefillPluginLog::warning('Cityselect integration call failed', [
                'message' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }
}
