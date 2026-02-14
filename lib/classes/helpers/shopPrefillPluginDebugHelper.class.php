<?php

/**
 * Хелпер для отладки состояния хранилища checkout_params
 *
 * Предоставляет функции для вывода состояния хранилища в режиме отладки:
 * - addDebugEntry() - добавить запись в стек дебага
 * - renderDebugStack() - вывести весь накопленный стек одним летающим окном
 * - renderErrorsDebugHtml() - вывести ошибки валидации (для checkout хуков)
 */
class shopPrefillPluginDebugHelper
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
            'data'  => $checkout_params,
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
        if (! in_array($hook_name, self::$called_hooks)) {
            self::$called_hooks[] = $hook_name;
        }
    }

    /**
     * Регистрирует отложенный вывод стека (через JavaScript callback)
     * Используется чтобы собрать записи из всех хуков перед выводом
     *
     * @return void
     */
    public static function scheduleDebugStackRender(): void
    {
        static $scheduled = false;

        if ($scheduled) {
            return;
        }

        $scheduled = true;

        // Регистрируем callback который выведет стек после загрузки DOM
        // Используем setTimeout чтобы дать время всем хукам отработать
        echo "<script>
        (function() {
            function renderPrefillDebugStack() {
                // Даём время на то чтобы все хуки выполнились и добавили записи
                setTimeout(function() {
                    if (window.PrefillDebugHelper && window.PrefillDebugHelper.renderStack) {
                        window.PrefillDebugHelper.renderStack();
                    }
                }, 100);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', renderPrefillDebugStack);
            } else {
                renderPrefillDebugStack();
            }
        })();
        </script>";
    }

    /**
     * Выводит весь накопленный стек дебага одним летающим окном
     *
     * @return void
     */
    public static function renderDebugStack(): void
    {
        if (empty(self::$debug_stack)) {
            return;
        }

        try {
            // Получаем экземпляр плагина
            $plugin = shopPrefillPlugin::getInstance();

            // Получаем настройки витрины
            $storefront_settings = $plugin->getStorefrontSettings();
            $plugin_enabled      = ! empty($storefront_settings['prefill']['active']);
            $zen_enabled         = ! empty($storefront_settings['zen']['active']);

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

                if (! isset($grouped_stack[$hook_name])) {
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
                                    'name'     => $error['name'] ?? $field_name,
                                    'text'     => $error['text'] ?? $error['message'] ?? 'Unknown error',
                                    'section'  => $error['section'] ?? '',
                                ];
                            } elseif (!empty($error)) {
                                // Массив без структуры, но не пустой - выводим содержимое
                                $normalized_errors[] = [
                                    'name'     => $field_name,
                                    'text'     => json_encode($error, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                                    'section'  => '',
                                ];
                            } else {
                                // Пустой массив - пропускаем или создаем информативное сообщение
                                $normalized_errors[] = [
                                    'name'     => $field_name,
                                    'text'     => 'Empty error data',
                                    'section'  => '',
                                ];
                            }
                        } elseif (is_string($error) && !empty($error)) {
                            // Если ошибка - строка, ключ - это имя поля
                            $normalized_errors[] = [
                                'name'     => $field_name,
                                'text'     => $error,
                                'section'  => '',
                            ];
                        } elseif (is_scalar($error)) {
                            // Число, boolean и т.д.
                            $normalized_errors[] = [
                                'name'     => $field_name,
                                'text'     => (string)$error,
                                'section'  => '',
                            ];
                        } elseif (!empty($error)) {
                            // Объект или другой тип
                            $normalized_errors[] = [
                                'name'     => $field_name,
                                'text'     => json_encode($error, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                                'section'  => '',
                            ];
                        }
                        // Пустые значения пропускаем
                    }
                    $errors_info['regular_errors'] = $normalized_errors;
                }

                $grouped_stack[$hook_name][] = [
                    'title'                   => $clean_title,
                    'data'                    => $entry['data'],
                    'color'                   => self::getEntryColor($entry['title']),
                    'sections_prefill_status' => $entry['sections_prefill_status'] ?? null,
                    'sections_filled_status'  => $entry['sections_filled_status'] ?? null,
                    'errors_info'             => $errors_info,
                ];
            }

            // Получаем параметры предзаполнения, которые плагин подготовил
            $fill_params_data = [];
            $fill_params_meta = [
                'user_authorized' => false,
                'user_id'         => null,
                'contact_id'      => null,
                'guest_hash'      => null,
                'orders_count'    => 0,
                'source'          => 'empty',
                'source_order_id' => null,
            ];

            try {
                // Проверяем авторизацию
                $user_provider      = $plugin->getUserProvider();
                $guest_hash_storage = $plugin->getGuestHashStorage();

                $fill_params_meta['user_authorized'] = $user_provider->isAuth();

                if ($fill_params_meta['user_authorized']) {
                    // Авторизованный пользователь
                    $fill_params_meta['user_id']    = $user_provider->getId();
                    $fill_params_meta['contact_id'] = $user_provider->getId();

                    // Получаем количество заказов
                    $order_provider                   = $plugin->getOrderProvider();
                    $orders_ids                       = $order_provider->getUserOrdersId((int) $fill_params_meta['user_id']);
                    $fill_params_meta['orders_count'] = count($orders_ids ?: []);
                } else {
                    // Гость: показываем укороченный хеш
                    $guest_hash                     = $guest_hash_storage->getGuestHash();
                    $fill_params_meta['guest_hash'] = $guest_hash ? substr($guest_hash, 0, 16) . '...' : null;

                    // Получаем количество заказов гостя
                    if ($guest_hash) {
                        $order_provider                   = $plugin->getOrderProvider();
                        $orders_ids                       = $order_provider->getAllOrderIdsByGuestHash($guest_hash);
                        $fill_params_meta['orders_count'] = count($orders_ids);
                    }
                }

                // Получаем параметры предзаполнения из БД
                $fill_params      = $plugin->getFillParamsProvider()->getFillParams();
                $fill_params_data = $fill_params->toArray();

                // Определяем источник данных
                $order_id = $fill_params->getId();
                if ($order_id) {
                    $fill_params_meta['source']          = 'order';
                    $fill_params_meta['source_order_id'] = $order_id;
                } elseif ($fill_params_meta['orders_count'] > 0) {
                    $fill_params_meta['source'] = 'orders (no data)';
                } else {
                    $fill_params_meta['source'] = 'empty (no orders)';
                }
            } catch (Exception $e) {
                $fill_params_meta['source'] = 'error: ' . $e->getMessage();
            }

            // Получаем текущее состояние хранилища checkout
            $current_storage = [];
            try {
                $session_storage = $plugin->getSessionStorageProvider();
                $current_storage = $session_storage->getCheckoutParams() ?: [];
            } catch (Exception $e) {
                // Игнорируем ошибки
            }

            // Подготавливаем данные для шаблона
            $template_vars = [
                'debug_stack'      => $grouped_stack,
                'plugin_enabled'   => $plugin_enabled,
                'zen_enabled'      => $zen_enabled,
                'has_orders'       => ($fill_params_meta['orders_count'] ?? 0) > 0,
                'fill_params'      => $fill_params_data,
                'fill_params_meta' => $fill_params_meta,
                'current_storage'  => $current_storage,
            ];

            // Рендерим шаблон
            $view = wa()->getView();
            $view->assign($template_vars);
            $debug_html = $view->fetch('string:' . file_get_contents(
                shopPrefillPlugin::getPluginPath() . '/templates/DebugStack.html'
            ));

            // Экранируем HTML для JavaScript
            // ВАЖНО: сначала экранируем одинарные кавычки, потом переносы строк
            $debug_html_escaped = str_replace("'", "\\'", $debug_html);
            $debug_html_escaped = str_replace(["\r\n", "\n", "\r"], "\\n", $debug_html_escaped);
            $debug_html_escaped = str_replace('"', '\\"', $debug_html_escaped);

            // Сохраняем HTML в window объект
            echo "<script>
            if (!window.PrefillDebugHelper) {
                window.PrefillDebugHelper = {};
            }

            // Обновляем HTML стека (последний вызов побеждает)
            window.PrefillDebugHelper.stackHtml = '{$debug_html_escaped}';

            // Функция для работы с куками
            window.PrefillDebugHelper.setCookie = function(name, value, days) {
                var expires = '';
                if (days) {
                    var date = new Date();
                    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                    expires = '; expires=' + date.toUTCString();
                }
                document.cookie = name + '=' + (value || '') + expires + '; path=/';
            };

            window.PrefillDebugHelper.getCookie = function(name) {
                var nameEQ = name + '=';
                var ca = document.cookie.split(';');
                for (var i = 0; i < ca.length; i++) {
                    var c = ca[i];
                    while (c.charAt(0) == ' ') c = c.substring(1, c.length);
                    if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
                }
                return null;
            };

            // Функция для сохранения состояния секции хранилища
            window.PrefillDebugHelper.toggleStorageDetails = function(details) {
                var isOpen = details.open ? '1' : '0';
                window.PrefillDebugHelper.setCookie('wa_prefill_debug_storage_open', isOpen, 365);
            };

            // Функция для рендера стека
            window.PrefillDebugHelper.renderStack = function() {
                var existing = document.getElementById('prefill-debug-stack');
                if (existing) {
                    existing.remove();
                }

                if (window.PrefillDebugHelper.stackHtml) {
                    var html = window.PrefillDebugHelper.stackHtml;
                    var tempDiv = document.createElement('div');
                    tempDiv.innerHTML = html;
                    document.body.appendChild(tempDiv.firstChild);

                    // Применяем сохраненное состояние (общее окно)
                    var savedState = window.PrefillDebugHelper.getCookie('wa_prefill_debug_collapsed');
                    var shouldCollapse = savedState === null ? true : (savedState === '1');

                    if (shouldCollapse) {
                         var body = document.getElementById('prefill-debug-body');
                         var btn = document.getElementById('prefill-debug-collapse-btn');
                         var container = document.getElementById('prefill-debug-stack');

                         if (body && btn && container) {
                             body.style.display = 'none';
                             btn.innerHTML = '➕';
                             container.style.width = 'auto';
                         }
                    }

                    // Применяем сохраненное состояние (секция хранилища)
                    var storageOpen = window.PrefillDebugHelper.getCookie('wa_prefill_debug_storage_open');
                    var storageDetails = document.getElementById('prefill-debug-storage-details');
                    if (storageDetails) {
                        // Если куки нет, оставляем как есть (open по умолчанию)
                        // Если кука есть, ставим состояние
                        if (storageOpen !== null) {
                            if (storageOpen === '1') {
                                storageDetails.setAttribute('open', '');
                            } else {
                                storageDetails.removeAttribute('open');
                            }
                        }
                    }

                    // Применяем сохраненное состояние (секции хуков)
                    try {
                        var hooksCookie = window.PrefillDebugHelper.getCookie('wa_prefill_debug_hooks_collapsed');
                        if (hooksCookie) {
                            var collapsedHooks = JSON.parse(decodeURIComponent(hooksCookie));
                            if (Array.isArray(collapsedHooks)) {
                                var headers = document.querySelectorAll('.prefill-debug-hook-header');
                                headers.forEach(function(header) {
                                    var hookName = header.getAttribute('data-hook');
                                    if (hookName && collapsedHooks.indexOf(hookName) !== -1) {
                                        var content = header.nextElementSibling;
                                        var arrow = header.querySelector('.arrow-icon');
                                        if (content) content.style.display = 'none';
                                        if (arrow) arrow.style.transform = 'rotate(-90deg)';
                                    }
                                });
                            }
                        }
                    } catch (e) {
                        console.error('Error restoring hooks state:', e);
                    }
                }
            };

            // ... wrappers ...

            // Функция для очистки хранилища
            window.PrefillDebugHelper.clearStorage = function() {
                if (!confirm('Очистить сессию shop/checkout?')) {
                    return;
                }

                var url = window.location.origin + '/shop/prefill/clear-storage';

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    console.log('Response status:', response.status);
                    return response.text();
                })
                .then(function(text) {
                    console.log('Response text:', text);
                    try {
                        var data = JSON.parse(text);
                        if (data.status === 'ok') {
                            alert('✅ Хранилище очищено!');
                            location.reload();
                        } else {
                            var errorMsg = data.errors ? JSON.stringify(data.errors) : 'Unknown error';
                            alert('❌ Ошибка: ' + errorMsg);
                        }
                    } catch(e) {
                        alert('❌ Ошибка парсинга JSON: ' + e.message + '\\n\\nОтвет сервера: ' + text.substring(0, 200));
                    }
                })
                .catch(function(err) {
                    alert('❌ Ошибка запроса: ' + err.message);
                    console.error('Clear storage error:', err);
                });
            };

            // Функция для переключения статуса плагина
            window.PrefillDebugHelper.togglePrefill = function() {
                if (!confirm('Переключить статус предзаполнения?')) {
                    return;
                }

                var url = window.location.origin + '/shop/prefill/toggle-prefill';

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({})
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (data.status === 'ok') {
                        location.reload();
                    } else {
                        alert('❌ Ошибка: ' + (data.errors ? JSON.stringify(data.errors) : 'Unknown error'));
                        location.reload();
                    }
                })
                .catch(function(err) {
                    alert('❌ Ошибка: ' + err.message);
                    location.reload();
                });
            };

            // Функция для сворачивания/разворачивания общего окна
            window.PrefillDebugHelper.toggleCollapse = function() {
                var body = document.getElementById('prefill-debug-body');
                var btn = document.getElementById('prefill-debug-collapse-btn');
                var container = document.getElementById('prefill-debug-stack');

                if (body.style.display === 'none') {
                    body.style.display = 'flex';
                    btn.innerHTML = '➖';
                    container.style.width = '';
                    window.PrefillDebugHelper.setCookie('wa_prefill_debug_collapsed', '0', 365);
                } else {
                    body.style.display = 'none';
                    btn.innerHTML = '➕';
                    container.style.width = 'auto';
                    window.PrefillDebugHelper.setCookie('wa_prefill_debug_collapsed', '1', 365);
                }
            };

            // Функция для сворачивания/разворачивания секции хука
            window.PrefillDebugHelper.toggleHookSection = function(headerElement) {
                // Находим следующий элемент (контейнер с контентом)
                var content = headerElement.nextElementSibling;
                var arrow = headerElement.querySelector('.arrow-icon');
                var hookName = headerElement.getAttribute('data-hook');

                if (content) {
                    var isCollapsed = false;
                    if (content.style.display === 'none') {
                        content.style.display = 'block';
                        if (arrow) arrow.style.transform = 'rotate(0deg)';
                        isCollapsed = false;
                    } else {
                        content.style.display = 'none';
                        if (arrow) arrow.style.transform = 'rotate(-90deg)';
                        isCollapsed = true;
                    }

                    // Сохраняем состояние в куки
                    if (hookName) {
                        try {
                            var cookieName = 'wa_prefill_debug_hooks_collapsed';
                            var cookieVal = window.PrefillDebugHelper.getCookie(cookieName);
                            var collapsedHooks = [];

                            if (cookieVal) {
                                try {
                                    collapsedHooks = JSON.parse(decodeURIComponent(cookieVal));
                                    if (!Array.isArray(collapsedHooks)) collapsedHooks = [];
                                } catch(e) { collapsedHooks = []; }
                            }

                            if (isCollapsed) {
                                if (collapsedHooks.indexOf(hookName) === -1) {
                                    collapsedHooks.push(hookName);
                                }
                            } else {
                                var index = collapsedHooks.indexOf(hookName);
                                if (index !== -1) {
                                    collapsedHooks.splice(index, 1);
                                }
                            }

                            window.PrefillDebugHelper.setCookie(cookieName, JSON.stringify(collapsedHooks), 365);
                        } catch (e) {
                            console.error('Error saving hook state:', e);
                        }
                    }
                }
            };

            // Функция для принудительного предзаполнения
            window.PrefillDebugHelper.forcePrefill = function() {
                var url = window.location.origin + '/shop/prefill/force-prefill';

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.status === 'ok') {
                        alert('✅ Предзаполнение выполнено! Страница будет перезагружена.');
                        location.reload();
                    } else {
                        alert('❌ Ошибка: ' + (data.errors ? JSON.stringify(data.errors) : 'Unknown error'));
                    }
                })
                .catch(function(err) {
                    alert('❌ Ошибка запроса: ' + err.message);
                });
            };

            // Функция для сброса и перезаполнения формы
            window.PrefillDebugHelper.resetAndRefill = function() {
                if (!confirm('Очистить всю форму и заново предзаполнить данными из последнего заказа?')) {
                    return;
                }

                var url = window.location.origin + '/shop/prefill/reset-and-refill';

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.status === 'ok') {
                        alert('✅ Форма очищена и перезаполнена! Страница будет перезагружена.');
                        location.reload();
                    } else {
                        alert('❌ Ошибка: ' + (data.errors ? JSON.stringify(data.errors) : 'Unknown error'));
                    }
                })
                .catch(function(err) {
                    alert('❌ Ошибка запроса: ' + err.message);
                });
            };

            // Функция для сброса флага first_prefill_done
            window.PrefillDebugHelper.resetFirstPrefillDone = function() {
                var url = window.location.origin + '/shop/prefill/reset-first-prefill-done';

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.status === 'ok') {
                        alert('✅ Флаг сброшен! Страница будет перезагружена.');
                        location.reload();
                    } else {
                        alert('❌ Ошибка: ' + (data.errors ? JSON.stringify(data.errors) : 'Unknown error'));
                    }
                })
                .catch(function(err) {
                    alert('❌ Ошибка запроса: ' + err.message);
                });
            };

            // Функция для переключения Zen Mode
            window.PrefillDebugHelper.toggleZen = function() {
                if (!confirm('Переключить статус Zen Mode?')) {
                    return;
                }

                var url = window.location.origin + '/shop/prefill/toggle-zen';

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({})
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.status === 'ok') {
                        location.reload();
                    } else {
                        alert('❌ Ошибка: ' + (data.errors ? JSON.stringify(data.errors) : 'Unknown error'));
                        location.reload();
                    }
                })
                .catch(function(err) {
                    alert('❌ Ошибка запроса: ' + err.message);
                    location.reload();
                });
            };

            // Функция управления меню действий
            window.PrefillDebugHelper.toggleActionsMenu = function(e) {
                if (e) { e.stopPropagation(); }
                var menu = document.getElementById('prefill-debug-actions-menu');
                if (menu) {
                    menu.style.display = (menu.style.display === 'none' || menu.style.display === '') ? 'block' : 'none';
                }
            };

            // Закрытие меню при клике вне
            document.addEventListener('click', function(e) {
                 var menu = document.getElementById('prefill-debug-actions-menu');
                 if (menu && menu.style.display === 'block') {
                     if (!e.target.closest('#prefill-debug-actions-menu') && !e.target.closest('button[onclick*=\"toggleActionsMenu\"]')) {
                         menu.style.display = 'none';
                     }
                 }
            });

            // Функция для обновления дебаг-панели через AJAX
            window.PrefillDebugHelper.refreshDebug = function() {
                console.log('🔄 refreshDebug called');

                var url = window.location.origin + '/shop/prefill/refresh-debug';
                var statusPanel = document.querySelector('#prefill-debug-body > div:first-child');
                var storagePanel = document.querySelector('#prefill-debug-body > div:nth-child(2)');
                var paramsPanel = document.querySelector('#prefill-debug-body > div:nth-child(3)');

                console.log('URL:', url);
                console.log('statusPanel:', statusPanel);
                console.log('storagePanel:', storagePanel);
                console.log('paramsPanel:', paramsPanel);

                if (!statusPanel || !storagePanel || !paramsPanel) {
                    console.error('Debug panels not found');
                    alert('❌ Не найдены панели дебага');
                    return;
                }

                // Показываем индикатор загрузки
                var originalStatusContent = statusPanel.innerHTML;
                statusPanel.innerHTML = '<div style=\"padding: 8px 15px; text-align: center; color: #666;\">⏳ Обновление...</div>';

                console.log('📡 Sending fetch request to:', url);

                fetch(url, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                    }
                    var contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(function(text) {
                            console.error('Non-JSON response:', text.substring(0, 500));
                            throw new Error('Сервер вернул не JSON (возможно, ошибка PHP)');
                        });
                    }
                    return response.json();
                })
                .then(function(data) {
                    console.log('✅ Received raw data:', data);

                    // waJsonController оборачивает response в data
                    var actualData = data.data || data;

                    console.log('Status:', actualData.status);
                    console.log('Plugin enabled:', actualData.plugin_enabled);
                    console.log('Timestamp:', actualData.timestamp);
                    console.log('Fill params meta:', actualData.fill_params_meta);
                    console.log('Fill params:', actualData.fill_params);
                    console.log('Errors:', actualData.errors);

                    if (actualData.status === 'ok') {
                        // Обновляем статус плагина
                        var bgColor = actualData.plugin_enabled ? '#d4edda' : '#f8d7da';
                        var borderColor = actualData.plugin_enabled ? '#28a745' : '#dc3545';

                        statusPanel.style.background = bgColor;
                        statusPanel.style.borderBottom = '1px solid ' + borderColor;
                        statusPanel.innerHTML = '<div style=\"display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px\">' +
                            '<div style=\"display: flex; align-items: center; gap: 8px\">' +
                                '<div style=\"display: flex; align-items: center; gap: 5px; padding: 3px 8px; background: ' + bgColor + '; border-radius: 3px; border: 1px solid ' + borderColor + ';\">' +
                                    '<span style=\"font-size: 9px; font-weight: bold; color: ' + (actualData.plugin_enabled ? '#155724' : '#721c24') + ';\">' +
                                        (actualData.plugin_enabled ? '✅ Prefill ON' : '⚠️ Prefill OFF') +
                                    '</span>' +
                                '</div>' +
                                '<div style=\"display: flex; align-items: center; gap: 5px; padding: 3px 8px; background: ' + (actualData.zen_enabled ? '#e8f5e9' : '#fce4ec') + '; border-radius: 3px; border: 1px solid ' + (actualData.zen_enabled ? '#4caf50' : '#f06292') + ';\">' +
                                    '<span style=\"font-size: 9px; font-weight: bold; color: ' + (actualData.zen_enabled ? '#2e7d32' : '#ad1457') + ';\">' +
                                        (actualData.zen_enabled ? '🧘 Zen ON' : '🧘 Zen OFF') +
                                    '</span>' +
                                '</div>' +
                            '</div>' +
                            '<div style=\"display: flex; gap: 5px; position: relative;\">' +
                                '<button onclick=\"PrefillDebugHelper.toggleActionsMenu(event)\" class=\"prefill-debug-btn\" style=\"background: #0277bd; color: white; border: none; border-radius: 3px; padding: 4px 8px; cursor: pointer; font-size: 10px; font-weight: bold;\" title=\"Меню действий\">⚡ Actions ▼</button>' +
                                '<div id=\"prefill-debug-actions-menu\" style=\"display: none; position: absolute; right: 0; top: 100%; background: white; border: 1px solid #ccc; border-radius: 4px; box-shadow: 0 4px 10px rgba(0,0,0,0.2); z-index: 100000; min-width: 200px; margin-top: 5px; color: #333; text-align: left;\">' +
                                    '<!-- PREFILL ACTIONS -->' +
                                    '<div style=\"padding: 6px 12px; background: #e3f2fd; border-bottom: 2px solid #2196f3; font-weight: bold; font-size: 10px; color: #1565c0; text-transform: uppercase; letter-spacing: 0.5px;\">📝 Prefill</div>' +
                                    '<div onclick=\"PrefillDebugHelper.togglePrefill()\" onmouseover=\"this.style.background=\\'#f5f5f5\\'\" onmouseout=\"this.style.background=\\'white\\'\" style=\"padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee;\">' +
                                        '<div style=\"font-weight: bold; font-size: 11px; color: #1565c0;\">🔄 Toggle Prefill</div>' +
                                        '<div style=\"font-size: 9px; color: #666;\">Вкл/Выкл предзаполнение</div>' +
                                    '</div>' +
                                    '<div onclick=\"PrefillDebugHelper.forcePrefill()\" onmouseover=\"this.style.background=\\'#f5f5f5\\'\" onmouseout=\"this.style.background=\\'white\\'\" style=\"padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee;\">' +
                                        '<div style=\"font-weight: bold; font-size: 11px; color: #2e7d32;\">⚡ Force Prefill</div>' +
                                        '<div style=\"font-size: 9px; color: #666;\">Заполнить принудительно (без очистки)</div>' +
                                    '</div>' +
                                    '<div onclick=\"PrefillDebugHelper.resetAndRefill()\" onmouseover=\"this.style.background=\\'#f5f5f5\\'\" onmouseout=\"this.style.background=\\'white\\'\" style=\"padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee;\">' +
                                        '<div style=\"font-weight: bold; font-size: 11px; color: #9c27b0;\">🔄 Reset & Refill</div>' +
                                        '<div style=\"font-size: 9px; color: #666;\">Очистить всё и заполнить заново</div>' +
                                    '</div>' +
                                    '<div onclick=\"PrefillDebugHelper.resetFirstPrefillDone()\" onmouseover=\"this.style.background=\\'#f5f5f5\\'\" onmouseout=\"this.style.background=\\'white\\'\" style=\"padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee;\">' +
                                        '<div style=\"font-weight: bold; font-size: 11px; color: #0277bd;\">🔁 Reset \\'First Done\\'</div>' +
                                        '<div style=\"font-size: 9px; color: #666;\">Сбросить флаг выполнения</div>' +
                                    '</div>' +
                                    '<div onclick=\"PrefillDebugHelper.clearStorage()\" onmouseover=\"this.style.background=\\'#f5f5f5\\'\" onmouseout=\"this.style.background=\\'white\\'\" style=\"padding: 8px 12px; cursor: pointer; border-bottom: 2px solid #ddd;\">' +
                                        '<div style=\"font-weight: bold; font-size: 11px; color: #ff9800;\">🗑️ Clear Storage</div>' +
                                        '<div style=\"font-size: 9px; color: #666;\">Полностью очистить сессию checkout</div>' +
                                    '</div>' +
                                    '<!-- ZEN MODE -->' +
                                    '<div style=\"padding: 6px 12px; background: #f3e5f5; border-bottom: 2px solid #9c27b0; font-weight: bold; font-size: 10px; color: #6a1b9a; text-transform: uppercase; letter-spacing: 0.5px;\">🧘 Zen Mode</div>' +
                                    '<div onclick=\"PrefillDebugHelper.toggleZen()\" onmouseover=\"this.style.background=\\'#f5f5f5\\'\" onmouseout=\"this.style.background=\\'white\\'\" style=\"padding: 8px 12px; cursor: pointer;\">' +
                                        '<div style=\"font-weight: bold; font-size: 11px; color: #673ab7;\">🧘 Toggle Zen Mode</div>' +
                                        '<div style=\"font-size: 9px; color: #666;\">Вкл/Выкл режим сворачивания</div>' +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>';

                        // Обновляем параметры предзаполнения
                        var meta = actualData.fill_params_meta || {};
                        var userInfo = meta.user_authorized
                            ? '✅ Авторизован (ID: ' + (meta.user_id || 'N/A') + ')'
                            : '❌ Гость' + (meta.guest_hash ? ' (hash: ' + meta.guest_hash + ')' : '');

                        var paramsDetailsContent = paramsPanel.querySelector('details > div');
                        if (paramsDetailsContent) {
                            paramsDetailsContent.innerHTML =
                                '<div style=\"background: #fff3e0; padding: 8px; border: 1px solid #ffb74d; border-radius: 4px; margin-bottom: 10px; font-size: 9px;\">' +
                                    '<strong>ℹ️ Метаданные:</strong><br />' +
                                    '• Пользователь: ' + userInfo + '<br />' +
                                    '• Заказов: ' + (meta.orders_count || 0) + '<br />' +
                                    '• Источник: ' + (meta.source || 'N/A') +
                                '</div>' +
                                '<pre style=\"margin: 0; padding: 10px; background: #fff; border: 1px solid #b3e5fc; border-radius: 4px; font-size: 9px; max-height: 150px; overflow: auto;\">' +
                                    JSON.stringify(actualData.fill_params || {}, null, 2) +
                                '</pre>';
                        }

                        // Обновляем актуальное состояние хранилища
                        if (storagePanel && actualData.checkout_params !== undefined) {
                            var storageDetailsContent = storagePanel.querySelector('details > div');
                            if (storageDetailsContent) {
                                var checkoutParams = actualData.checkout_params || {};
                                var isEmpty = Object.keys(checkoutParams).length === 0;

                                if (isEmpty) {
                                    storageDetailsContent.innerHTML =
                                        '<p style=\"color: #ff5722; font-weight: bold; font-size: 10px; margin: 0; padding: 10px; background: #fff; border: 1px solid #ffcdd2; border-radius: 4px;\">' +
                                            '❌ Хранилище пустое' +
                                        '</p>';
                                } else {
                                    var hasOrder = checkoutParams.order !== undefined;
                                    var hasAuth = hasOrder && checkoutParams.order.auth !== undefined;
                                    var hasAuthData = hasAuth && checkoutParams.order.auth.data !== undefined;
                                    var hasRegion = hasOrder && checkoutParams.order.region !== undefined;
                                    var hasShipping = hasOrder && checkoutParams.order.shipping !== undefined;
                                    var hasDetails = checkoutParams['details-section'] !== undefined;
                                    var hasPayment = checkoutParams['payment-section'] !== undefined;
                                    var hasConfirm = checkoutParams['confirm-section'] !== undefined;

                                    var prefillMetadata = checkoutParams.prefill_metadata || {};
                                    var firstPrefillDone = prefillMetadata.first_prefill_done === true;
                                    var hasFirstPrefillDone = prefillMetadata.first_prefill_done !== undefined;

                                    var structureHtml =
                                        '<div style=\"background: #fff; padding: 10px; border: 1px solid #a5d6a7; border-radius: 4px; margin-bottom: 10px; font-size: 10px; line-height: 1.6;\">' +
                                            '<strong style=\"color: #2e7d32;\">📊 Структура данных:</strong><br />';

                                    if (hasFirstPrefillDone) {
                                        structureHtml += 'first_prefill_done: ' + (firstPrefillDone ? '✅' : '❌') + '<br />';
                                    }

                                    structureHtml +=
                                            'order: ' + (hasOrder ? '✅' : '❌') + '<br />';

                                    if (hasOrder) {
                                        structureHtml +=
                                            '└─ auth: ' + (hasAuth ? '✅' : '❌');
                                        if (hasAuth) {
                                            structureHtml += ' → data: ' + (hasAuthData ? '✅' : '❌');
                                        }
                                        structureHtml +=
                                            '<br />└─ region: ' + (hasRegion ? '✅' : '❌') +
                                            '<br />└─ shipping: ' + (hasShipping ? '✅' : '❌') + '<br />';
                                    }

                                    structureHtml +=
                                        '└─ details: ' + (hasDetails ? '✅' : '❌') +
                                        '<br />└─ payment: ' + (hasPayment ? '✅' : '❌') +
                                        '<br />└─ confirm: ' + (hasConfirm ? '✅' : '❌') +
                                        '</div>';

                                    storageDetailsContent.innerHTML = structureHtml +
                                        '<pre style=\"margin: 0; padding: 10px; background: #fff; border: 1px solid #a5d6a7; border-radius: 4px; font-size: 9px; max-height: 200px; overflow: auto;\">' +
                                            JSON.stringify(checkoutParams, null, 2) +
                                        '</pre>';
                                }
                            }
                        }

                        console.log('✅ Debug refreshed:', actualData);
                    } else {
                        statusPanel.innerHTML = originalStatusContent;
                        var errorMsg = 'Unknown error';
                        if (actualData.errors) {
                            if (typeof actualData.errors === 'string') {
                                errorMsg = actualData.errors;
                            } else if (actualData.errors.error) {
                                errorMsg = actualData.errors.error;
                            } else {
                                errorMsg = JSON.stringify(actualData.errors);
                            }
                        }
                        console.error('❌ Server error:', errorMsg);
                        alert('❌ Ошибка обновления: ' + errorMsg);
                    }
                })
                .catch(function(err) {
                    statusPanel.innerHTML = originalStatusContent;
                    alert('❌ Ошибка запроса: ' + err.message);
                    console.error('Refresh error:', err);
                });
            };

            // Проверяем что функция создана
            console.log('✅ PrefillDebugHelper.refreshDebug registered');
            </script>";

        } catch (Exception $e) {
            // Фоллбэк - выводим ошибку
            echo "<script>console.error('Debug render error:', " . json_encode($e->getMessage()) . ");</script>";
        }

        // НЕ очищаем стек здесь! Он будет очищаться при следующем вызове
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
        if (! $errors_info['has_errors']) {
            return '';
        }

        static $style_output = false;
        $debug_html = '';
        if (! $style_output) {
            $debug_html .= '<style>.prefill-errors-debug[open] .prefill-errors-debug-arrow{transform:rotate(90deg)}</style>';
            $style_output = true;
        }

        $debug_html .= '<details class="prefill-errors-debug" style="background: #f8d7da; margin: 10px; border: 2px solid #dc3545; border-radius: 5px;">';
        $debug_html .= '<summary style="padding: 12px 15px; cursor: pointer; font-weight: bold; user-select: none; list-style: none; display: flex; align-items: center; gap: 6px;">';
        $debug_html .= '<span class="prefill-errors-debug-arrow" style="font-size: 14px; display: inline-block; transition: transform 0.2s;">▶</span>';
        $debug_html .= '⚠️ ' . htmlspecialchars($hook_name) . ': Обнаружены незаполненные обязательные поля!';
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
                $field_name  = ifset($error, 'name', 'unknown');
                $error_text  = ifset($error, 'text', 'Unknown error');
                $section     = ifset($error, 'section', '');
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
