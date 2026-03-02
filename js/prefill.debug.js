/**
 * Prefill Plugin Debug Helper
 * 
 * Provides utility functions for the debug panel:
 * - Cookie management
 * - Toggle functionality (Zen Mode, Validation Errors, etc.)
 * - AJAX storage clearing and prefill forcing
 * - Debug panel UI management (collapse/expand)
 */

(function () {
    // Ensure the helper object exists
    window.PrefillDebugHelper = window.PrefillDebugHelper || {};

    /**
     * Set a cookie
     * @param {string} name 
     * @param {string} value 
     * @param {number} days 
     */
    window.PrefillDebugHelper.setCookie = function (name, value, days) {
        var expires = "";
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
        }
        document.cookie = name + "=" + (value || "") + expires + "; path=/";
        console.log('🍪 Set cookie:', name, value);
    };

    /**
     * Get a cookie
     * @param {string} name 
     * @returns {string|null}
     */
    window.PrefillDebugHelper.getCookie = function (name) {
        var nameEQ = name + "=";
        var ca = document.cookie.split(';');
        for (var i = 0; i < ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) == ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
        }
        return null;
    };

    /**
     * Init function to restore UI state
     */
    window.PrefillDebugHelper.init = function () {
        console.log('🚀 Prefill Debug JS initialized');

        // Render stack if available
        if (window.PrefillDebugHelper.stackHtml) {
            var existing = document.getElementById('prefill-debug-stack');
            if (existing) {
                existing.remove();
            }

            var tempDiv = document.createElement('div');
            tempDiv.innerHTML = window.PrefillDebugHelper.stackHtml;
            if (tempDiv.firstElementChild) {
                document.body.appendChild(tempDiv.firstElementChild);
            }
        }

        // Restore main panel collapse state
        var collapsed = window.PrefillDebugHelper.getCookie('wa_prefill_debug_collapsed');
        if (collapsed === '1') {
            var body = document.getElementById('prefill-debug-body');
            var btn = document.getElementById('prefill-debug-collapse-btn');
            var container = document.getElementById('prefill-debug-stack');

            if (body && btn && container) {
                body.style.display = 'none';
                btn.innerHTML = '➕';
                container.style.width = 'auto';
            }
        }

        // Restore storage section state
        var storageOpen = window.PrefillDebugHelper.getCookie('wa_prefill_debug_storage_open');
        var storageDetails = document.getElementById('prefill-debug-storage-details');
        if (storageDetails) {
            if (storageOpen !== null) {
                if (storageOpen === '1') {
                    storageDetails.setAttribute('open', '');
                } else {
                    storageDetails.removeAttribute('open');
                }
            }
        }

        // Restore hook sections state
        try {
            var hooksCookie = window.PrefillDebugHelper.getCookie('wa_prefill_debug_hooks_collapsed');
            if (hooksCookie) {
                var collapsedHooks = JSON.parse(decodeURIComponent(hooksCookie));
                if (Array.isArray(collapsedHooks)) {
                    var headers = document.querySelectorAll('.prefill-debug-hook-header');
                    headers.forEach(function (header) {
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

        // Close actions menu when clicking outside
        document.addEventListener('click', function (e) {
            var menu = document.getElementById('prefill-debug-actions-menu');
            if (menu && menu.style.display === 'block') {
                if (!e.target.closest('#prefill-debug-actions-menu') && !e.target.closest('button[onclick*="toggleActionsMenu"]')) {
                    menu.style.display = 'none';
                }
            }
        });
    };

    // --- Actions ---

    window.PrefillDebugHelper.clearStorage = function () {
        if (!confirm('Очистить сессию shop/checkout?')) return;

        var url = (window.PrefillDebugHelper.baseUrl || '/shop/') + 'prefill/clear-storage';
        fetchAction(url, function (data) {
            alert('✅ Хранилище очищено!');
            location.reload();
        });
    };

    window.PrefillDebugHelper.togglePrefill = function () {
        if (!confirm('Переключить статус предзаполнения?')) return;

        var url = (window.PrefillDebugHelper.baseUrl || '/shop/') + 'prefill/toggle-prefill';
        fetchAction(url, function (data) {
            console.log('✅ Prefill toggled:', data);
            // Обновляем панель без перезагрузки страницы
            window.PrefillDebugHelper.refreshDebug();
        }, {}, 'POST', true);
    };

    window.PrefillDebugHelper.toggleStorageDetails = function (details) {
        var isOpen = details.open ? '1' : '0';
        window.PrefillDebugHelper.setCookie('wa_prefill_debug_storage_open', isOpen, 365);
    };

    window.PrefillDebugHelper.toggleCollapse = function () {
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

    window.PrefillDebugHelper.toggleHookSection = function (headerElement) {
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

            if (hookName) {
                updateHooksCookie(hookName, isCollapsed);
            }
        }
    };

    window.PrefillDebugHelper.forcePrefill = function () {
        var url = (window.PrefillDebugHelper.baseUrl || '/shop/') + 'prefill/force-prefill';
        fetchAction(url, function (data) {
            alert('✅ Предзаполнение выполнено! Страница будет перезагружена.');
            location.reload();
        });
    };

    window.PrefillDebugHelper.resetAndRefill = function () {
        if (!confirm('Очистить всю форму и заново предзаполнить данными из последнего заказа?')) return;

        var url = (window.PrefillDebugHelper.baseUrl || '/shop/') + 'prefill/reset-and-refill';
        fetchAction(url, function (data) {
            alert('✅ Форма очищена и перезаполнена! Страница будет перезагружена.');
            location.reload();
        });
    };

    window.PrefillDebugHelper.resetSnapshot = function () {
        if (!confirm('Clear Prefill Snapshot storage?')) return;

        var url = (window.PrefillDebugHelper.baseUrl || '/shop/') + 'prefill/reset-snapshot';
        fetchAction(url, function (data) {
            alert('✅ Snapshot cleared! Page will reload.');
            location.reload();
        });
    };

    window.PrefillDebugHelper.toggleZen = function () {
        if (!confirm('Переключить статус Zen Mode?')) return;

        var url = (window.PrefillDebugHelper.baseUrl || '/shop/') + 'prefill/toggle-zen';
        fetchAction(url, function (data) {
            window.PrefillDebugHelper.refreshDebug();
        }, {}, 'POST', true);
    };

    window.PrefillDebugHelper.toggleValidationErrors = function () {
        var cookieName = 'wa_prefill_debug_show_validation';
        var currentVal = window.PrefillDebugHelper.getCookie(cookieName);
        if (currentVal === '1') {
            window.PrefillDebugHelper.setCookie(cookieName, '0', 365);
            alert('🚫 Ошибки валидации СКРЫТЫ');
        } else {
            window.PrefillDebugHelper.setCookie(cookieName, '1', 365);
            alert('✅ Ошибки валидации ВКЛЮЧЕНЫ');
        }
        location.reload();
    };

    window.PrefillDebugHelper.toggleActionsMenu = function (e) {
        if (e) { e.stopPropagation(); }
        var menu = document.getElementById('prefill-debug-actions-menu');
        if (menu) {
            menu.style.display = (menu.style.display === 'none' || menu.style.display === '') ? 'block' : 'none';
        }
    };

    window.PrefillDebugHelper.refreshDebug = function () {
        console.log('🔄 refreshDebug called');
        var url = (window.PrefillDebugHelper.baseUrl || '/shop/') + 'prefill/refresh-debug';
        var statusPanel = document.querySelector('#prefill-debug-body > div:first-child');
        var storagePanel = document.querySelector('#prefill-debug-body > div:nth-child(2)');
        var paramsPanel = document.querySelector('#prefill-debug-body > div:nth-child(3)');

        if (!statusPanel || !storagePanel || !paramsPanel) {
            console.error('Debug panels not found');
            alert('❌ Не найдены панели дебага');
            return;
        }

        var originalStatusContent = statusPanel.innerHTML;
        statusPanel.innerHTML = '<div style="padding: 8px 15px; text-align: center; color: #666;">⏳ Обновление...</div>';

        fetch(url, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                var contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(function (text) {
                        throw new Error('Server returned non-JSON');
                    });
                }
                return response.json();
            })
            .then(function (data) {
                var actualData = data.data || data;
                if (actualData.status === 'ok') {
                    if (actualData.html_status) {
                        var newStatus = document.createElement('div');
                        newStatus.innerHTML = actualData.html_status;
                        if (newStatus.firstElementChild) statusPanel.replaceWith(newStatus.firstElementChild);
                    }
                    if (actualData.html_storage) {
                        var newStorage = document.createElement('div');
                        newStorage.innerHTML = actualData.html_storage;
                        if (newStorage.firstElementChild) storagePanel.replaceWith(newStorage.firstElementChild);
                    }
                    if (actualData.html_params) {
                        var newParams = document.createElement('div');
                        newParams.innerHTML = actualData.html_params;
                        if (newParams.firstElementChild) paramsPanel.replaceWith(newParams.firstElementChild);
                    }
                    console.log('✅ Debug refreshed HTML');
                } else {
                    statusPanel.innerHTML = originalStatusContent;
                    handleError(actualData, 'Ошибка обновления');
                }
            })
            .catch(function (err) {
                statusPanel.innerHTML = originalStatusContent;
                alert('❌ Ошибка запроса: ' + err.message);
                console.error('Refresh error:', err);
            });
    };

    // --- Helpers ---

    function fetchAction(url, successCallback, body = {}, method = 'POST', noAlertOnError = false) {
        fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: method === 'POST' ? JSON.stringify(body) : null
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.status === 'ok') {
                    successCallback(data);
                } else {
                    handleError(data, 'Ошибка', noAlertOnError);
                }
            })
            .catch(function (err) {
                alert('❌ Ошибка запроса: ' + err.message);
            });
    }

    function handleError(data, prefix, noAlert) {
        var errorMsg = data.errors ? (
            typeof data.errors === 'string' ? data.errors : JSON.stringify(data.errors)
        ) : 'Unknown error';
        console.error('Server error:', errorMsg);
        if (!noAlert) {
            alert('❌ ' + prefix + ': ' + errorMsg);
        }
    }

    function updateHooksCookie(hookName, isCollapsed) {
        try {
            var cookieName = 'wa_prefill_debug_hooks_collapsed';
            var cookieVal = window.PrefillDebugHelper.getCookie(cookieName);
            var collapsedHooks = [];

            if (cookieVal) {
                try {
                    collapsedHooks = JSON.parse(decodeURIComponent(cookieVal));
                    if (!Array.isArray(collapsedHooks)) collapsedHooks = [];
                } catch (e) { collapsedHooks = []; }
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

    // Auto-init
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', window.PrefillDebugHelper.init);
    } else {
        window.PrefillDebugHelper.init();
    }

})();
