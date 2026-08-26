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
     * Полная очистка: отзыв согласия и очистка истории. Кука гасится немедленно.
     */
    public function clear(): void
    {
        try {
            $this->storage->remove(self::SESSION_KEY);
        } catch (Throwable $e) {
            shopPrefillPluginLog::warning('Failed clearing geo session state', ['message' => $e->getMessage()]);
        }

        $this->response->setCookie(self::COOKIE_NAME, '', time() - 3600, null, '', false, true);
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

        $this->response->setCookie(
            self::COOKIE_NAME,
            $payload,
            time() + self::COOKIE_TTL,
            null,
            '',
            false,
            true
        );
    }
}
