<?php

/**
 * Класс для отладки состояния хранилища checkout_params
 *
 * - addDebugEntry() - добавить запись в стек дебага
 * - renderDebugStack() - вывести весь накопленный стек одним летающим окном
 * - renderErrorsDebugHtml() - вывести ошибки валидации (для checkout хуков)
 */
class shopPrefillPluginDebug
{
    /**
     * Стек дебаг-записей для накопления
     * @var array
     */
    private static array $debug_stack = [];

    /**
     * Список вызванных хуков (для диагностики)
     * @var array
     */
    private static array $called_hooks = [];

    /**
     * Добавляет запись в стек дебага
     *
     * @param mixed  $checkout_params Данные из хранилища
     * @param string $title           Заголовок записи
     * @param array  $extra           Дополнительные данные (например, sections_prefill_status)
     * @return void
     */
    public static function addDebugEntry($checkout_params, string $title, array $extra = []): void
    {
        self::$debug_stack[] = array_merge([
            'title' => $title,
            'data' => $checkout_params,
        ], $extra);
    }

    /**
     * Регистрирует вызов хука (для диагностики)
     *
     * @param string $hook_name Имя хука
     * @return void
     */
    public static function registerHookCall(string $hook_name): void
    {
        if (!in_array($hook_name, self::$called_hooks)) {
            self::$called_hooks[] = $hook_name;
        }
    }

    /**
     * Выводит весь накопленный стек дебага одним летающим окном
     *
     * @return string
     */
    public static function renderDebugStack(): string
    {
        static $rendered = false;

        if ($rendered) {
            return '';
        }

        if (empty(self::$debug_stack)) {
            return '';
        }

        try {
            $rendered = true;

            // Получаем экземпляр плагина
            $plugin = shopPrefillPlugin::getInstance();

            // Получаем настройки витрины
            $storefront_settings = $plugin->getEffectiveStorefrontSettings();
            $plugin_enabled = !empty($storefront_settings['active']);
            $zen_enabled = !empty($storefront_settings['zen']['active']);

            // Группируем стек по хукам
            $grouped_stack = [];
            foreach (self::$debug_stack as $entry) {
                $hook_name = 'General';
                if (preg_match('/\((.+)\)/', $entry['title'], $matches)) {
                    $hook_name = $matches[1];
                }

                $clean_title = $entry['title'];
                if (stripos($entry['title'], 'BEFORE') !== false) {
                    $clean_title = 'BEFORE';
                } elseif (stripos($entry['title'], 'AFTER') !== false) {
                    $clean_title = 'AFTER';
                }

                if (!isset($grouped_stack[$hook_name])) {
                    $grouped_stack[$hook_name] = [];
                }

                // Нормализуем errors_info для безопасного отображения в шаблоне
                $errors_info = $entry['errors_info'] ?? null;
                if ($errors_info && isset($errors_info['regular_errors']) && is_array($errors_info['regular_errors'])) {
                    // Убеждаемся, что все элементы regular_errors имеют нужную структуру
                    $normalized_errors = [];
                    foreach ($errors_info['regular_errors'] as $key => $error) {
                        $field_name = is_string($key) && !empty($key) ? $key : 'error';

                        if (is_array($error)) {
                            // Если это массив, проверяем структуру
                            if (isset($error['name']) || isset($error['text']) || isset($error['message'])) {
                                // Структурированная ошибка с полями name/text/message
                                $normalized_errors[] = [
                                    'name' => $error['name'] ?? $field_name,
                                    'text' => $error['text'] ?? $error['message'] ?? 'Unknown error',
                                    'section' => $error['section'] ?? '',
                                ];
                            } elseif (!empty($error)) {
                                // Массив без структуры, но не пустой - выводим содержимое
                                $normalized_errors[] = [
                                    'name' => $field_name,
                                    'text' => json_encode($error, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                                    'section' => '',
                                ];
                            } else {
                                // Пустой массив - пропускаем или создаем информативное сообщение
                                $normalized_errors[] = [
                                    'name' => $field_name,
                                    'text' => 'Empty error data',
                                    'section' => '',
                                ];
                            }
                        } elseif (is_string($error) && !empty($error)) {
                            // Если ошибка - строка, ключ - это имя поля
                            $normalized_errors[] = [
                                'name' => $field_name,
                                'text' => $error,
                                'section' => '',
                            ];
                        } elseif (is_scalar($error)) {
                            // Число, boolean и т.д.
                            $normalized_errors[] = [
                                'name' => $field_name,
                                'text' => (string) $error,
                                'section' => '',
                            ];
                        } elseif (!empty($error)) {
                            // Объект или другой тип
                            $normalized_errors[] = [
                                'name' => $field_name,
                                'text' => json_encode($error, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                                'section' => '',
                            ];
                        }
                        // Пустые значения пропускаем
                    }
                    $errors_info['regular_errors'] = $normalized_errors;
                }

                $grouped_stack[$hook_name][] = [
                    'title' => $clean_title,
                    'data' => $entry['data'],
                    'color' => self::getEntryColor($entry['title']),
                    'sections_prefill_status' => $entry['sections_prefill_status'] ?? null,
                    'sections_filled_status' => $entry['sections_filled_status'] ?? null,
                    'errors_info' => $errors_info,
                ];
            }

            // Собираем общие отладочные данные
            $debug_data = self::collectDebugData($plugin);
            $fill_params_data = $debug_data['fill_params_data'];
            $fill_params_meta = $debug_data['fill_params_meta'];
            $current_storage = $debug_data['current_storage'];
            $snapshot_storage = $debug_data['snapshot_storage'];

            // Подготавливаем данные для шаблона
            $template_vars = [
                'debug_stack' => $grouped_stack,
                'plugin_enabled' => $plugin_enabled,
                'zen_enabled' => $zen_enabled,
                'has_orders' => ($fill_params_meta['orders_count'] ?? 0) > 0,
                'fill_params' => $fill_params_data,
                'fill_params_meta' => $fill_params_meta,
                'current_storage' => $current_storage,
                'snapshot_storage' => $snapshot_storage,
                'show_validation' => waRequest::cookie('wa_prefill_debug_show_validation', 0),
            ];

            // Рендерим шаблон
            $view = wa()->getView();
            $view->assign($template_vars);

            $template_path = shopPrefillPlugin::getPluginPath() . '/templates/debug/';
            $html_status     = $view->fetch('file:' . $template_path . 'DebugStatusPanel.html');
            $html_storage    = $view->fetch('file:' . $template_path . 'DebugStorageDetails.html');
            $html_fill_params = $view->fetch('file:' . $template_path . 'DebugFillParams.html');
            $view->assign('html_status', $html_status);
            $view->assign('html_storage', $html_storage);
            $view->assign('html_fill_params', $html_fill_params);

            $debug_html = $view->fetch('string:' . file_get_contents(
                $template_path . 'DebugStack.html'
            ));

            $debug_html_json = json_encode($debug_html, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($debug_html_json === false) {
                $debug_html_json = json_encode('', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            // Подключаем JS и CSS файлы
            $plugin_id = 'prefill';
            $static_base = wa()->getAppStaticUrl('shop') . "plugins/{$plugin_id}/";
            $version = date('YmdHi');
            $js_url  = $static_base . "js/prefill.debug.js?v={$version}";
            $css_url = $static_base . "css/prefill.debug.css?v={$version}";

            // Определяем базовый URL для AJAX запросов
            $base_url = wa()->getRouteUrl('shop/frontend');
            $base_url_json = json_encode($base_url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($base_url_json === false) {
                $base_url_json = json_encode('', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            // Передаем данные в JS
            return "<link rel=\"stylesheet\" href=\"{$css_url}\">"
                . "<script src=\"{$js_url}\"></script>"
                . "<script>
            (function() {
                window.PrefillDebugHelper = window.PrefillDebugHelper || {};
                window.PrefillDebugHelper.stackHtml = {$debug_html_json};
                window.PrefillDebugHelper.baseUrl = {$base_url_json};
            })();
            </script>";
        } catch (Exception $e) {
            // Фоллбэк - выводим ошибку
            shopPrefillPluginLog::error('Critical error rendering debug stack in shopPrefillPluginDebug', [
                'message' => $e->getMessage()
            ]);
            return "<script>console.error('Prefill debug render error:', " . json_encode($e->getMessage()) . ");</script>";
        }

        // НЕ очищаем стек здесь! Он будет очищаться при следующем вызове
    }

    /**
     * Собирает общие отладочные данные о параметрах предзаполнения и хранилище
     *
     * @param shopPrefillPlugin $plugin
     * @return array
     */
    public static function collectDebugData(shopPrefillPlugin $plugin): array
    {
        $fill_params_data = [];
        $fill_params_meta = [
            'user_authorized' => false,
            'user_id' => null,
            'contact_id' => null,
            'guest_lookup' => null,
            'orders_count' => 0,
            'source' => 'empty',
            'source_order_id' => null,
        ];

        try {
            // Проверяем авторизацию
            $user_provider = $plugin->getUserProvider();
            $guest_token_storage = $plugin->getGuestTokenStorage();

            $fill_params_meta['user_authorized'] = $user_provider->isAuth();

            if ($fill_params_meta['user_authorized']) {
                // Авторизованный пользователь
                $fill_params_meta['user_id'] = $user_provider->getId();
                $fill_params_meta['contact_id'] = $user_provider->getId();

                // Получаем количество заказов
                $order_provider = $plugin->getOrderProvider();
                $orders_ids = $order_provider->getUserOrdersId((int) $fill_params_meta['user_id']);
                $fill_params_meta['orders_count'] = count($orders_ids ?: []);
            } else {
                // Гость: показываем префикс производного lookup id, а не сам токен из куки
                $param_name = $guest_token_storage->getParamName();
                $fill_params_meta['guest_lookup'] = $param_name === null
                    ? null
                    : substr($param_name, 0, 22) . '...';

                // Пустая кука — штатное состояние посетителя без заказов, а не ошибка
                if ($param_name !== null) {
                    $order_provider = $plugin->getOrderProvider();
                    $orders_ids = $order_provider->getOrderIdsByGuestParam($param_name);
                    $fill_params_meta['orders_count'] = count($orders_ids);
                }
            }

            // Получаем параметры предзаполнения из БД
            $fill_params = $plugin->getFillParamsProvider()->getFillParams();
            $fill_params_data = $fill_params->toArray();

            // Определяем источник данных
            $order_id = $fill_params->getId();
            if ($order_id) {
                $fill_params_meta['source'] = 'order';
                $fill_params_meta['source_order_id'] = $order_id;
            } elseif ($fill_params_meta['orders_count'] > 0) {
                $fill_params_meta['source'] = 'orders (no data)';
            } else {
                $fill_params_meta['source'] = 'empty (no orders)';
            }
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Failed preparing debug info for fill params in shopPrefillPluginDebug', [
                'message' => $e->getMessage()
            ]);
            $fill_params_meta['source'] = 'error: ' . $e->getMessage();
        }

        // Получаем текущее состояние хранилища checkout и snapshot
        $current_storage = [];
        $snapshot_storage = [];
        try {
            $session_storage = $plugin->getSessionStorageProvider();
            $current_storage = $session_storage->getCheckoutParams();
            $snapshot_storage = $session_storage->getSnapshot() ?: [];
        } catch (Exception $e) {
            shopPrefillPluginLog::warning('Failed fetching session storage in shopPrefillPluginDebug', [
                'message' => $e->getMessage()
            ]);
        }

        return [
            'fill_params_data' => $fill_params_data,
            'fill_params_meta' => $fill_params_meta,
            'current_storage' => $current_storage,
            'snapshot_storage' => $snapshot_storage,
        ];
    }

    /**
     * Возвращает цвет для записи в зависимости от заголовка
     *
     * @param string $title
     * @return string
     */
    private static function getEntryColor(string $title): string
    {
        if (stripos($title, 'BEFORE') !== false) {
            return '#ff9800'; // Оранжевый для BEFORE
        }
        if (stripos($title, 'AFTER') !== false) {
            return '#4caf50'; // Зелёный для AFTER
        }
        return '#2196f3'; // Синий по умолчанию
    }

    /**
     * Рендерит HTML для отображения ошибок валидации checkout
     *
     * @param array  $errors_info Массив с информацией об ошибках
     * @param string $hook_name   Название хука/секции
     * @return string HTML для вставки
     */
    public static function renderErrorsDebugHtml(array $errors_info, string $hook_name = 'CONFIRM SECTION'): string
    {
        if (!$errors_info['has_errors']) {
            return '';
        }

        // Проверяем куку отображения ошибок валидации (по умолчанию скрыто)
        $show_validation = waRequest::cookie('wa_prefill_debug_show_validation', 0);
        if (!$show_validation) {
            return '';
        }

        static $style_output = false;
        $debug_html = '';
        if (!$style_output) {
            $debug_html .= '<style>.prefill-errors-debug[open] .prefill-errors-debug-arrow{transform:rotate(90deg)}</style>';
            $style_output = true;
        }

        $debug_html .= '<details class="prefill-errors-debug" style="background: #f8d7da; margin: 10px; border: 2px solid #dc3545; border-radius: 5px;">';
        $debug_html .= '<summary style="padding: 12px 15px; cursor: pointer; font-weight: bold; user-select: none; list-style: none; display: flex; align-items: center; gap: 6px;">';
        $debug_html .= '<span class="prefill-errors-debug-arrow" style="font-size: 14px; display: inline-block; transition: transform 0.2s;">▶</span>';
        $debug_html .= '⚠️ ' . htmlspecialchars($hook_name) . ': Обнаружены незаполненные обязательные поля!';
        $debug_html .= '<span style="font-size: 10px; font-weight: normal; color: #666; margin-left: auto;">(Debug info)</span>';
        $debug_html .= '</summary>';
        $debug_html .= '<div style="padding: 0 15px 15px 15px; border-top: 1px solid #dc3545;">';
        $debug_html .= '<p style="margin: 5px 0 10px 0; color: #721c24;">Нельзя скрывать поля - пользователь не сможет их заполнить!</p>';

        // КРИТИЧЕСКИЕ ОШИБКИ (блокируют checkout, влияют на расчет доставки)
        if ($errors_info['regular_errors']) {
            $debug_html .= '<div style="background: #ffcccc; padding: 10px; margin-top: 10px; border: 2px solid #dc3545; border-radius: 3px;">';
            $debug_html .= '<strong>🚨 КРИТИЧЕСКИЕ ОШИБКИ (блокируют checkout):</strong>';
            if ($errors_info['error_step_id']) {
                $debug_html .= '<p style="margin: 5px 0; font-size: 12px;">Шаг с ошибкой: <code>' . htmlspecialchars($errors_info['error_step_id']) . '</code></p>';
            }
            $debug_html .= '<ul style="margin: 5px 0; padding-left: 20px;">';
            foreach ($errors_info['regular_errors'] as $error) {
                $field_name = ifset($error, 'name', 'unknown');
                $error_text = ifset($error, 'text', 'Unknown error');
                $section = ifset($error, 'section', '');
                $debug_html .= '<li><code>' . htmlspecialchars($field_name) . '</code>';
                if ($section) {
                    $debug_html .= ' <span style="font-size: 11px; color: #666;">(' . htmlspecialchars($section) . ')</span>';
                }
                $debug_html .= ': ' . htmlspecialchars($error_text) . '</li>';
            }
            $debug_html .= '</ul>';
            $debug_html .= '<p style="margin: 5px 0 0 0; font-size: 12px; color: #721c24;"><strong>Важно:</strong> Эти поля влияют на расчет стоимости/доступности доставки</p>';
            $debug_html .= '</div>';
        }

        // ОТЛОЖЕННЫЕ ОШИБКИ - Auth (не блокируют, но проверяются при создании заказа)
        if ($errors_info['auth_delayed_errors']) {
            $debug_html .= '<div style="background: #fff3cd; padding: 10px; margin-top: 10px; border: 1px solid #ffc107; border-radius: 3px;">';
            $debug_html .= '<strong>📝 Auth errors (секция авторизации):</strong>';
            $debug_html .= '<ul style="margin: 5px 0; padding-left: 20px;">';
            foreach ($errors_info['auth_delayed_errors'] as $field_name => $error_text) {
                $debug_html .= '<li><code>' . htmlspecialchars($field_name) . '</code>: ' . htmlspecialchars($error_text) . '</li>';
            }
            $debug_html .= '</ul></div>';
        }

        // SERVICE AGREEMENT ERROR (чекбокс согласия с условиями)
        if ($errors_info['service_agreement_error']) {
            $debug_html .= '<div style="background: #ffebee; padding: 10px; margin-top: 10px; border: 2px solid #f44336; border-radius: 3px;">';
            $debug_html .= '<strong>⚠️ Service Agreement (чекбокс согласия с условиями):</strong>';
            $debug_html .= '<p style="margin: 5px 0; padding-left: 20px; color: #c62828;">';
            $debug_html .= '<code>auth[service_agreement]</code>: Пользователь должен согласиться с условиями обслуживания';
            $debug_html .= '</p></div>';
        }

        // ОТЛОЖЕННЫЕ ОШИБКИ - Details (не блокируют, но проверяются при создании заказа)
        if ($errors_info['details_delayed_errors']) {
            $debug_html .= '<div style="background: #fff3cd; padding: 10px; margin-top: 10px; border: 1px solid #ffc107; border-radius: 3px;">';
            $debug_html .= '<strong>🚚 Details errors (секция доставки):</strong>';
            $debug_html .= '<ul style="margin: 5px 0; padding-left: 20px;">';
            foreach ($errors_info['details_delayed_errors'] as $field_name => $error_text) {
                $debug_html .= '<li><code>' . htmlspecialchars($field_name) . '</code>: ' . htmlspecialchars($error_text) . '</li>';
            }
            $debug_html .= '</ul></div>';
        }

        $debug_html .= '<div style="background: #e7f3ff; padding: 10px; margin-top: 10px; border: 1px solid #0066cc; border-radius: 3px;">';
        $debug_html .= '<strong>💡 Решение:</strong> Не скрывать блоки формы, если есть ЛЮБЫЕ ошибки (критические или delayed)';
        $debug_html .= '</div>';

        $debug_html .= '</div></details>';

        return $debug_html;
    }
}
