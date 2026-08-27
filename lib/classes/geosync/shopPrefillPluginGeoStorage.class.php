<?php

/**
 * Состояние гео-синхронизации: что мы записали сторонним плагинам и откуда взяли город.
 *
 * Живёт в двух местах, и оба нужны:
 *
 *  - сессия `shop/prefill_geo` — гейт похода в БД: источник читается не чаще раза за сессию;
 *  - кука `prefill_geo` — тот же слепок на год. Без неё каждая новая сессия заново ходила бы
 *    в БД (состояние regions сессионное и восстанавливается с нуля), а правило G1 не могло бы
 *    отличить нашу прошлую запись от выбора покупателя между сессиями.
 *
 * Факт предложения перехода (`proposed`) живёт **только в сессии** и в куку не попадает.
 * Причина не в экономии: у `writeCookie()` предохранитель `COOKIE_MAX_LENGTH`, и при
 * превышении кука молча не пишется вовсе — а без неё правило G1a на каждой новой сессии
 * считало бы встречу первой и один раз перезаписывало город покупателя. Третья карта в
 * payload приблизила бы этот порог без всякой пользы: предложение по своей природе
 * сессионное, потому что сессионно и хранилище локации у regions.
 *
 * Запись в сессию поднимает PHP-сессию и `Set-Cookie: PHPSESSID`, поэтому вызывающий обязан
 * убедиться, что покупатель опознан (`source_key !== null`) — иначе нарушается P5: просмотр
 * каталога анонимом не создаёт ничего. Чтение сессии бесплатно: `waSessionStorage::read()`
 * сессию не открывает.
 */
class shopPrefillPluginGeoStorage
{
    private const SESSION_KEY = 'shop/prefill_geo';
    private const COOKIE_NAME = 'prefill_geo';
    private const COOKIE_TTL  = 365 * 86400;

    /** Защита от мусорной куки: больше этого не читаем вовсе */
    private const COOKIE_MAX_LENGTH = 512;

    private waSessionStorage $storage;
    private waResponse $response;

    public function __construct(waSessionStorage $storage, waResponse $response)
    {
        $this->storage  = $storage;
        $this->response = $response;
    }

    /**
     * Источник, уже прочитанный в этой сессии. Совпал с текущим — в БД не идём.
     */
    public function getLoadedSource(): ?string
    {
        $state = $this->readSession();
        $value = $state['source'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Город, ради которого всё затевалось. Сначала сессия, затем кука прошлых визитов.
     */
    public function getTarget(): shopPrefillPluginGeoTarget
    {
        $state = $this->readSession();

        if (! empty($state['target'])) {
            return shopPrefillPluginGeoTarget::fromArray($state['target']);
        }

        return shopPrefillPluginGeoTarget::fromArray($this->readCookie()['target'] ?? null);
    }

    /**
     * Что мы записали этому плагину. `null` означает «мы сюда ещё не писали» — по правилу
     * G1a это первая встреча, и она разрешает однократную перезапись.
     */
    public function getApplied(string $adapter_id): ?shopPrefillPluginGeoTarget
    {
        $state = $this->readSession();
        $applied = $state['applied'][$adapter_id] ?? ($this->readCookie()['applied'][$adapter_id] ?? null);

        if (! is_array($applied)) {
            return null;
        }

        return shopPrefillPluginGeoTarget::fromArray($applied);
    }

    /**
     * Предлагали ли мы уже переход этому плагину в этой сессии.
     *
     * Только сессия: чужое хранилище локации тоже сессионное, а между сессиями предложение
     * не имеет смысла — их окно всё равно спросит заново.
     */
    public function hasProposed(string $adapter_id): bool
    {
        $state = $this->readSession();

        return ! empty($state['proposed'][$adapter_id]);
    }

    /**
     * Фиксирует факт предложения перехода. Вызывать только для опознанного покупателя.
     */
    public function markProposed(string $adapter_id): void
    {
        $state = $this->readSession();

        if (! isset($state['proposed']) || ! is_array($state['proposed'])) {
            $state['proposed'] = [];
        }

        $state['proposed'][$adapter_id] = true;

        // Куку не трогаем: предложение в неё не входит (см. док-блок класса)
        $this->writeSession($state);
    }

    /**
     * Запоминает прочитанный источник и город. Вызывать только для опознанного покупателя.
     */
    public function rememberTarget(string $source_key, shopPrefillPluginGeoTarget $target): void
    {
        $state           = $this->readSession();
        $state['source'] = $source_key;
        $state['target'] = $target->toArray();

        $this->writeSession($state);
        $this->writeCookie($state);
    }

    /**
     * Фиксирует факт записи городa в конкретный плагин.
     */
    public function markApplied(string $adapter_id, shopPrefillPluginGeoTarget $target): void
    {
        $state = $this->readSession();

        if (! isset($state['applied']) || ! is_array($state['applied'])) {
            $state['applied'] = [];
        }

        $state['applied'][$adapter_id] = $target->toArray();

        $this->writeSession($state);
        $this->writeCookie($state);
    }

    /**
     * Переставляет куку своими атрибутами, если она пришла в этом запросе.
     *
     * Зачем: при переходе между поддоменами оба соседних плагина переносят **все** куки
     * (`shopCityselectHelper::pushCookies()`, `shopRegionsEnvService::saveCurrentEnv()`),
     * но восстанавливают их голым `setCookie($name, $value)` — без `httponly`, а у regions
     * ещё и без срока жизни, отчего кука становится сессионной. Сами мы этого не замечаем:
     * PHP-сессия переезд переживает, `getLoadedSource()` совпадает, и `rememberTarget()`
     * на новом домене уже не вызывается — испорченная кука так и осталась бы жить.
     *
     * Отдельный сессионный флаг «уже переставили» не заводим: лишняя запись в сессию дороже
     * одного заголовка `Set-Cookie`, а скользящий срок для кэша с годовым TTL безвреден.
     */
    public function refreshCookie(): void
    {
        $cookie = $this->readCookie();

        if (empty($cookie)) {
            return;
        }

        $this->writeCookie([
            'target'  => $cookie['target'] ?? [],
            'applied' => $cookie['applied'] ?? [],
        ]);
    }

    /**
     * Полная очистка: отзыв согласия и очистка истории. Кука гасится немедленно.
     */
    public function clear(): void
    {
        try {
            $this->storage->remove(self::SESSION_KEY);
        } catch (Throwable $e) {
            shopPrefillPluginLog::warning('Failed clearing geo session state', ['message' => $e->getMessage()]);
        }

        $this->response->setCookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'secure'   => waRequest::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readSession(): array
    {
        $state = $this->storage->get(self::SESSION_KEY);

        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeSession(array $state): void
    {
        try {
            $this->storage->set(self::SESSION_KEY, $state);
        } catch (Throwable $e) {
            shopPrefillPluginLog::warning('Failed writing geo session state', ['message' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readCookie(): array
    {
        $raw = waRequest::cookie(self::COOKIE_NAME, '', waRequest::TYPE_STRING_TRIM);

        if ($raw === '' || strlen($raw) > self::COOKIE_MAX_LENGTH) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeCookie(array $state): void
    {
        // Источник в куку не кладём: он идентифицирует покупателя, а куке жить год.
        $payload = json_encode([
            'target'  => $state['target'] ?? [],
            'applied' => $state['applied'] ?? [],
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false || strlen($payload) > self::COOKIE_MAX_LENGTH) {
            return;
        }

        // Атрибуты те же, что у prefill_guest_token и prefill_consent: раньше здесь стоял
        // позиционный вызов с secure = false и без samesite, и кука была слабее остальных
        $this->response->setCookie(
            self::COOKIE_NAME,
            $payload,
            [
                'expires'  => time() + self::COOKIE_TTL,
                'secure'   => waRequest::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }
}
