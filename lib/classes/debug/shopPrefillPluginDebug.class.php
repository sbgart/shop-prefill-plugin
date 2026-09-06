<?php

/**
 * Диагностика checkout Prefill.
 *
 * События живут только в памяти одного PHP-запроса. Render-хуки передают их браузеру
 * через <template>, поэтому debug не пишет служебные данные в checkout-сессию или БД.
 */
class shopPrefillPluginDebug
{
    private const MAX_EVENTS = 50;
    private const MAX_DUMP_BYTES = 32768;

    private static bool $enabled = false;
    private static array $events = [];
    private static int $sent_events = 0;
    private static ?string $request_id = null;
    private static int $sequence = 0;

    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /** @param array<string, mixed> $data */
    public static function recordEvent(string $stage, string $label, array $data = []): void
    {
        if (!self::$enabled || count(self::$events) >= self::MAX_EVENTS) {
            return;
        }

        self::$events[] = [
            'request_id' => self::getRequestId(),
            'sequence' => ++self::$sequence,
            'time' => date('H:i:s'),
            'stage' => $stage,
            'label' => $label,
            'data' => self::sanitize($data),
        ];
    }

    /** Передаёт браузеру только ещё не отправленные события текущего запроса. */
    public static function renderPendingEvents(): string
    {
        if (!self::$enabled || self::$sent_events >= count(self::$events)) {
            return '';
        }

        $events = array_slice(self::$events, self::$sent_events);
        self::$sent_events = count(self::$events);

        return shopPrefillPluginViewProvider::render('debug/DebugEventsCarrier', [
            'request_id' => self::getRequestId(),
            'request_type' => self::getRequestType(),
            'events' => self::prepareEventsForView($events),
        ]);
    }

    public static function renderDebugPanel(): string
    {
        if (!self::$enabled) {
            return '';
        }

        try {
            $plugin = shopPrefillPlugin::getInstance();
            $vars = self::collectCurrentState($plugin);
            $vars['initial_events'] = self::prepareEventsForView(
                array_slice(self::$events, self::$sent_events)
            );
            $vars['request_id'] = self::getRequestId();
            $vars['request_type'] = self::getRequestType();
            $vars['is_admin'] = wa()->getUser()->isAdmin('shop');
            $vars['settings_url'] = wa()->getConfig()->getBackendUrl(true) . '/shop/?plugin=prefill&action=settings';

            $html = shopPrefillPluginViewProvider::render('debug/DebugStack', $vars);
            self::$sent_events = count(self::$events);

            $static_base = wa()->getAppStaticUrl('shop') . 'plugins/prefill/';
            $version = date('YmdHi');
            $config = [
                'baseUrl' => wa()->getRouteUrl('shop/frontend'),
                'maxRequests' => 10,
                'messages' => [
                    'loading' => _wp('debug.loading'),
                    'request_error' => _wp('debug.request_error'),
                    'clear_confirm' => _wp('debug.clear_confirm'),
                    'refill_confirm' => _wp('debug.refill_confirm'),
                    'done' => _wp('debug.done'),
                ],
            ];

            return '<link rel="stylesheet" href="' . htmlspecialchars($static_base . 'css/prefill.debug.css?v=' . $version) . '">'
                . '<script>window.PrefillDebugConfig=' . self::json(
                    $config,
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                ) . ';</script>'
                . '<script src="' . htmlspecialchars($static_base . 'js/prefill.debug.js?v=' . $version) . '"></script>'
                . '<template id="prefill-debug-bootstrap">' . $html . '</template>';
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Failed rendering Prefill debug panel', ['message' => $e->getMessage()]);
            return '';
        }
    }

    /** Текущее состояние без чтения истории заказов. */
    public static function collectCurrentState(shopPrefillPlugin $plugin): array
    {
        $provider = $plugin->getStorefrontProvider();
        $current = $provider->findCurrentStorefront();
        $effective = $plugin->getEffectiveStorefront();
        $settings = $effective->getSettings();
        $user = $plugin->getUserProvider();
        $guest = $plugin->getGuestTokenStorage();
        $storage = $plugin->getSessionStorageProvider();
        $lookup = $guest->getParamName();
        $checkout = $storage->getCheckoutParams();

        return [
            'plugin_enabled' => !empty($settings['active']),
            'zen_enabled' => !empty($settings['zen']['active']),
            'logging_level' => shopPrefillPluginLog::getConfiguredLevel(),
            'current_storefront' => $current ? $current->getFullUrl() : _wp('debug.not_observed'),
            'effective_storefront' => $effective->getFullUrl(),
            'uses_global_fallback' => $effective->getCode() === shopPrefillPluginStorefrontProvider::GLOBAL_CODE,
            'user_authorized' => $user->isAuth(),
            'user_id' => $user->isAuth() ? $user->getId() : null,
            'guest_lookup' => $lookup === null ? null : substr($lookup, 0, 22) . '...',
            'guest_consent' => waRequest::cookie(shopPrefillPluginConsentStorage::CONSENT_COOKIE) === '1',
            'source_key' => self::shortSourceKey($plugin->getFillParamsProvider()->getSourceKey()),
            'applied_source' => self::shortSourceKey($storage->getAppliedSource()),
            'current_storage' => self::sanitize($checkout),
            'storage_dump' => self::dump($checkout),
            'source_loaded' => false,
            'fill_params' => [],
            'fill_params_dump' => '',
            'source_order_id' => null,
        ];
    }

    /** Явное диагностическое чтение источника. Данные не применяются к форме. */
    public static function loadSource(shopPrefillPlugin $plugin): array
    {
        $state = self::collectCurrentState($plugin);
        $fill_params = $plugin->getFillParamsProvider()->getFillParams();
        $data = $fill_params->toArray();
        $state['source_loaded'] = true;
        $state['fill_params'] = self::sanitize($data);
        $state['fill_params_dump'] = self::dump($data);
        $state['source_order_id'] = $fill_params->getId();
        return $state;
    }

    /** Блок ошибок checkout включается отдельной debug-cookie. */
    public static function renderErrorsDebugHtml(array $errors_info, string $hook_name = 'confirm'): string
    {
        if (empty($errors_info['has_errors']) || !waRequest::cookie('wa_prefill_debug_show_validation', 0)) {
            return '';
        }

        return shopPrefillPluginViewProvider::render('debug/DebugValidationErrors', [
            'hook_name' => $hook_name,
            'errors_info' => self::normalizeErrors($errors_info),
        ]);
    }

    /** @param array<int, array<string, mixed>> $events */
    private static function prepareEventsForView(array $events): array
    {
        foreach ($events as &$event) {
            $event['dump'] = self::dump($event['data']);
        }
        unset($event);
        return $events;
    }

    private static function getRequestId(): string
    {
        if (self::$request_id === null) {
            try {
                self::$request_id = substr(bin2hex(random_bytes(8)), 0, 12);
            } catch (Exception $e) {
                self::$request_id = substr(sha1(uniqid('', true)), 0, 12);
            }
        }
        return self::$request_id;
    }

    private static function getRequestType(): string
    {
        $uri = (string) waRequest::server('REQUEST_URI', '');
        if (strpos($uri, '/order/calculate') !== false) {
            return 'calculate';
        }
        if (strpos($uri, '/order/create') !== false) {
            return 'create';
        }
        return waRequest::isXMLHttpRequest() ? 'ajax' : 'page';
    }

    private static function shortSourceKey(?string $value): ?string
    {
        if ($value === null || strpos($value, 'guest:') !== 0) {
            return $value;
        }
        return substr($value, 0, 14) . '...';
    }

    private static function sanitize($value, int $depth = 0)
    {
        if ($depth > 8) {
            return '[depth limit]';
        }
        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 200, true) as $key => $item) {
                if (preg_match('/token|cookie|csrf|password/i', (string) $key)) {
                    $result[$key] = '[redacted]';
                } else {
                    $result[$key] = self::sanitize($item, $depth + 1);
                }
            }
            if (count($value) > 200) {
                $result['__truncated__'] = count($value) - 200;
            }
            return $result;
        }
        if (is_object($value)) {
            return '[object ' . get_class($value) . ']';
        }
        if (is_string($value)) {
            if (strpos($value, 'guest:') === 0 || strpos($value, 'prefill_guest_') === 0) {
                return substr($value, 0, 14) . '...';
            }
            if (strlen($value) > 4096) {
                return substr($value, 0, 4096) . '…';
            }
        }
        return $value;
    }

    private static function dump($data): string
    {
        $dump = self::json(self::sanitize($data), JSON_PRETTY_PRINT);
        return strlen($dump) > self::MAX_DUMP_BYTES
            ? substr($dump, 0, self::MAX_DUMP_BYTES) . "\n[truncated]"
            : $dump;
    }

    private static function json($data, int $flags = 0): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $flags);
        return $json === false ? 'null' : $json;
    }

    private static function normalizeErrors(array $errors_info): array
    {
        $regular = [];
        foreach (($errors_info['regular_errors'] ?? []) as $key => $error) {
            $name = is_string($key) ? $key : 'error';
            if (is_array($error)) {
                $regular[] = [
                    'name' => $error['name'] ?? $name,
                    'text' => $error['text'] ?? $error['message'] ?? self::dump($error),
                    'section' => $error['section'] ?? '',
                ];
            } else {
                $regular[] = ['name' => $name, 'text' => (string) $error, 'section' => ''];
            }
        }
        $errors_info['regular_errors'] = $regular;
        return self::sanitize($errors_info);
    }
}
