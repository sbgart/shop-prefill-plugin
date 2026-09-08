<?php

/**
 * Защёлка дзен-режима: «покупатель уже вмешался в эту группу, не закрывать её под руками» (Z4).
 *
 * Хранится в куках `prefill_zen_{group}=expanded`, по одной на группу, сессионных
 * (`expires => 0`). Пишет их не только сервер: `ZenModeToggle.js` ставит ту же куку на клике
 * «Изменить» и снимает на «Свернуть» — поэтому формат значения принадлежит обеим сторонам
 * и меняться не может без правки клиента.
 *
 * Защёлка принадлежит личности, а не браузеру. Кука переживает и PHP-сессию, и вход в аккаунт,
 * а ставится она при **любом** развороте — в том числе когда группа развёрнута не покупателем,
 * а серверным промахом («заполнять ещё нечего», Z2). Без привязки к личности гостевой визит
 * глушил дзен-режим авторизованному покупателю: все три группы решались на кука-ветке
 * `shouldCollapseGroup()`, не доходя до проверки данных.
 *
 * Привязка сделана отдельной кукой-владельцем `prefill_zen_owner` с хешем отпечатка личности
 * (`FillParamsProvider::getSourceKey()` — тем же, которым штампуются `ZenSummaryCache` и
 * `GeoStorage`, R4). Не штампом внутри значения защёлки — именно потому, что значение пишет
 * ещё и JS: штамп внутри пришлось бы рендерить в разметку и подставлять на клиенте, иначе
 * сервер не узнавал бы собственную куку после клика «Изменить». Куку-владельца пишет только
 * сервер, формат защёлок при этом не меняется вовсе.
 *
 * См. docs/plans/zen-collapse-latch-identity-scope.md,
 * docs/bugs/zen-collapse-latch-outlives-php-session.md, Z4 в docs/concept/RULES.md.
 */
class shopPrefillPluginZenLatch
{
    /** Префикс кук состояния групп. Значение знает и клиент (js/modules/ZenModeToggle.js). */
    public const COOKIE_PREFIX = 'prefill_zen_';

    /** Кука-владелец: хеш отпечатка личности, которой принадлежат защёлки. Пишет только сервер. */
    private const OWNER_COOKIE = 'prefill_zen_owner';

    /** Единственное значение защёлки. */
    private const EXPANDED = 'expanded';

    /**
     * Группы, чьи защёлки чистятся при смене личности и после заказа.
     *
     * Список продублирован намеренно: обратная ссылка на shopPrefillPluginZenMode сделала бы
     * низкоуровневое хранилище зависимым от координатора. Совпадение с GROUP_SECTIONS заперто
     * тестом tests/ZenGroupCarrierTest.php.
     */
    private const GROUPS = ['customer', 'delivery', 'payment'];

    /**
     * Отпечаток гостя без куки токена: `getSourceKey()` возвращает для него null, и это
     * полноценный владелец, а не «владельца нет». Маркер с \0 не столкнётся ни с одним
     * настоящим ключом ('user:<id>' / 'guest:<lookup_id>').
     */
    private const OWNER_ANONYMOUS = "\0anonymous";

    private waRequest $request;

    private waResponse $response;

    private shopPrefillPluginFillParamsProvider $fill_params_provider;

    /** Сверка владельца делается один раз за запрос — см. reconcile(). */
    private bool $reconciled = false;

    /** Отпечаток не вычислился: защёлки не действуют, но и не переписываются. */
    private bool $disabled = false;

    public function __construct(
        waRequest $request,
        waResponse $response,
        shopPrefillPluginFillParamsProvider $fill_params_provider
    ) {
        $this->request              = $request;
        $this->response             = $response;
        $this->fill_params_provider = $fill_params_provider;
    }

    /**
     * Держит ли покупатель эту группу развёрнутой.
     *
     * @param string $group Имя группы
     * @return bool
     */
    public function isExpanded(string $group): bool
    {
        $this->reconcile();

        if ($this->disabled) {
            return false;
        }

        return $this->request->cookie(self::COOKIE_PREFIX . $group) === self::EXPANDED;
    }

    /**
     * Синхронизирует защёлку группы с фактическим состоянием при каждом обновлении формы.
     * Группа осталась развёрнутой — защёлка ставится (в том числе когда разворот вызван
     * ошибками или нехваткой данных, Z4); свернулась — снимается.
     *
     * @param string $group Имя группы
     * @param bool $is_collapsed Свёрнута ли группа
     */
    public function sync(string $group, bool $is_collapsed): void
    {
        $this->reconcile();

        if ($is_collapsed) {
            $this->forget($group);
        } else {
            $this->response->setCookie(self::COOKIE_PREFIX . $group, self::EXPANDED, [
                'expires'  => 0,
                'path'     => '/',
                'samesite' => 'Lax',
            ]);
        }
    }

    /**
     * Снимает защёлки всех групп. Вызывается после создания заказа вместе с очисткой кэша
     * сводки: иначе следующий заказ открылся бы развёрнутым по решениям предыдущего.
     * Куку-владельца не трогает — личность не менялась.
     */
    public function clearAll(): void
    {
        foreach (self::GROUPS as $group) {
            $this->forget($group);
        }
    }

    /**
     * Сверяет владельца защёлок с текущей личностью — один раз за запрос.
     *
     * Ровно один раз, потому что блоки трёх групп рендерятся в разных хуках, а
     * waResponse::setCookie() попутно правит $_COOKIE: сверяй мы на каждом чтении, вторая
     * группа увидела бы уже обновлённого владельца и сочла бы, что всё совпало.
     *
     * Чужие защёлки именно снимаются, а не игнорируются: иначе возврат к прежней личности
     * (логаут обратно в гостя) воскресил бы её защёлку, а группа, чей блок в этом запросе не
     * отрисован (короткое замыкание), унесла бы чужую защёлку в следующий запрос.
     */
    private function reconcile(): void
    {
        if ($this->reconciled) {
            return;
        }
        $this->reconciled = true;

        $owner = $this->currentOwner();

        // Отпечаток не вычислился: деградируем в «дзен без защёлки», а не в «чужая защёлка».
        // Ничего не пишем — состояние починится само на первом же удачном запросе.
        if ($owner === null) {
            $this->disabled = true;
            return;
        }

        $previous = $this->request->cookie(self::OWNER_COOKIE);

        if ($previous === $owner) {
            return;
        }

        $this->clearAll();

        $this->response->setCookie(self::OWNER_COOKIE, $owner, [
            'expires'  => 0,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);

        // Первый кадр в браузере — тоже промах (куки-владельца ещё нет), но это не смена
        // личности: логируем только реальную, иначе маркер бесполезен в прогоне.
        if ($previous !== null && $previous !== '') {
            shopPrefillPluginLog::debug('Zen latches dropped: identity changed');
        }
    }

    /**
     * Хеш отпечатка текущей личности.
     *
     * В куке лежит именно хеш, а не сырой ключ: сырой — это 'user:<contact_id>', а кука видна
     * клиенту. Подделка неопасна (защёлка управляет только разворотом блока), поэтому HMAC не
     * нужен — достаточно короткого хеша для сравнения.
     *
     * @return string|null null означает «отпечаток недоступен», а не «гость»
     */
    private function currentOwner(): ?string
    {
        try {
            $source_key = $this->fill_params_provider->getSourceKey();
        } catch (Throwable $e) {
            shopPrefillPluginLog::warning('Failed resolving zen latch owner', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        return substr(md5($source_key ?? self::OWNER_ANONYMOUS), 0, 12);
    }

    /** Удаляет куку группы. */
    private function forget(string $group): void
    {
        $this->response->setCookie(self::COOKIE_PREFIX . $group, '', [
            'expires'  => -1, // отрицательное время = удаление
            'path'     => '/',
            'samesite' => 'Lax',
        ]);
    }
}
