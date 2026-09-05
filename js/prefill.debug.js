(function () {
    'use strict';

    var config = window.PrefillDebugConfig || {};
    var maxRequests = config.maxRequests || 10;
    var observer = null;
    var themeObserver = null;
    var themeMedia = null;

    function panel() {
        return document.getElementById('prefill-debug-stack');
    }

    function message(key) {
        return (config.messages && config.messages[key]) || key;
    }

    function colorLuminance(color) {
        var match = String(color || '').match(/^rgba?\((\d+)[, ]+\s*(\d+)[, ]+\s*(\d+)(?:[, /]+\s*([\d.]+))?\)$/i);
        if (!match || (match[4] !== undefined && Number(match[4]) < 0.2)) return null;
        var channels = [Number(match[1]), Number(match[2]), Number(match[3])].map(function (value) {
            value /= 255;
            return value <= 0.03928 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
        });
        return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
    }

    function detectColorScheme() {
        var elements = [document.body, document.documentElement];
        var themeHint = elements.map(function (element) {
            if (!element) return '';
            return [
                element.getAttribute('data-theme'),
                element.getAttribute('data-color-scheme'),
                element.className
            ].filter(Boolean).join(' ');
        }).join(' ').toLowerCase();
        if (/(^|[\s_-])dark([\s_-]|$)/.test(themeHint)) return 'dark';
        if (/(^|[\s_-])light([\s_-]|$)/.test(themeHint)) return 'light';

        for (var i = 0; i < elements.length; i++) {
            if (!elements[i]) continue;
            var luminance = colorLuminance(window.getComputedStyle(elements[i]).backgroundColor);
            if (luminance !== null) return luminance < 0.32 ? 'dark' : 'light';
        }

        var declaredScheme = window.getComputedStyle(document.documentElement).colorScheme.trim();
        if (declaredScheme === 'dark' || declaredScheme === 'only dark') return 'dark';
        if (declaredScheme === 'light' || declaredScheme === 'only light') return 'light';
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function syncColorScheme() {
        var rootPanel = panel();
        if (rootPanel) rootPanel.setAttribute('data-color-scheme', detectColorScheme());
    }

    function scheduleThemeSync() {
        window.requestAnimationFrame(syncColorScheme);
    }

    function observeColorScheme() {
        syncColorScheme();
        themeObserver = new MutationObserver(scheduleThemeSync);
        themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class', 'style', 'data-theme', 'data-color-scheme']
        });
        if (document.body) {
            themeObserver.observe(document.body, {
                attributes: true,
                attributeFilter: ['class', 'style', 'data-theme', 'data-color-scheme']
            });
        }
        if (window.matchMedia) {
            themeMedia = window.matchMedia('(prefers-color-scheme: dark)');
            if (themeMedia.addEventListener) themeMedia.addEventListener('change', scheduleThemeSync);
            else if (themeMedia.addListener) themeMedia.addListener(scheduleThemeSync);
        }
    }

    function stopObservers() {
        if (observer) observer.disconnect();
        if (themeObserver) themeObserver.disconnect();
        if (themeMedia) {
            if (themeMedia.removeEventListener) themeMedia.removeEventListener('change', scheduleThemeSync);
            else if (themeMedia.removeListener) themeMedia.removeListener(scheduleThemeSync);
        }
    }

    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + days * 86400000);
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value || '') + expires + '; path=/; SameSite=Lax';
    }

    function getCookie(name) {
        var prefix = name + '=';
        var values = document.cookie.split(';');
        for (var i = 0; i < values.length; i++) {
            var item = values[i].trim();
            if (item.indexOf(prefix) === 0) {
                return decodeURIComponent(item.substring(prefix.length));
            }
        }
        return null;
    }

    function parseHtml(html) {
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        return parsed.body.firstElementChild;
    }

    function setStatus(text, isError) {
        var target = panel() && panel().querySelector('.pd-action-status');
        if (!target) return;
        target.textContent = text || '';
        target.classList.toggle('is-error', Boolean(isError));
    }

    function request(path, method) {
        var options = {
            method: method || 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        };
        if (options.method === 'POST') {
            options.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
            options.body = '_csrf=' + encodeURIComponent(getCookie('_csrf') || '');
        }
        return fetch((config.baseUrl || '/shop/') + path, options)
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (payload) {
                var data = payload.data || payload;
                if (data.status !== 'ok') {
                    throw new Error(typeof data.errors === 'string' ? data.errors : JSON.stringify(data.errors || {}));
                }
                return data;
            });
    }

    function replaceFromHtml(selector, html) {
        var current = panel() && panel().querySelector(selector);
        var replacement = parseHtml(html);
        if (current && replacement) current.replaceWith(document.importNode(replacement, true));
    }

    function runAction(button, callback) {
        button.disabled = true;
        setStatus(message('loading'), false);
        callback().then(function () {
            setStatus(message('done'), false);
        }).catch(function (error) {
            setStatus(message('request_error') + ': ' + error.message, true);
        }).then(function () {
            button.disabled = false;
        });
    }

    function refresh(button) {
        runAction(button, function () {
            return request('prefill/refresh-debug', 'GET').then(function (data) {
                var trace = panel().querySelector('#prefill-debug-trace');
                replaceFromHtml('#prefill-debug-state', data.html);
                var replacementTrace = panel().querySelector('#prefill-debug-trace');
                if (trace && replacementTrace) replacementTrace.replaceWith(trace);
            });
        });
    }

    function loadSource(button) {
        runAction(button, function () {
            return request('prefill/debug-source', 'GET').then(function (data) {
                replaceFromHtml('#prefill-debug-source', data.html);
            });
        });
    }

    function mutate(button, path, confirmation) {
        if (confirmation && !window.confirm(confirmation)) return;
        runAction(button, function () {
            return request(path, 'POST').then(function () {
                window.location.reload();
            });
        });
    }

    function handleAction(event) {
        var button = event.target.closest('[data-debug-action]');
        if (!button || !panel() || !panel().contains(button)) return;

        var action = button.getAttribute('data-debug-action');
        if (action === 'close') {
            stopObservers();
            panel().remove();
        } else if (action === 'collapse') {
            var body = document.getElementById('prefill-debug-body');
            var collapsed = body.hidden = !body.hidden;
            button.textContent = collapsed ? '+' : '−';
            setCookie('wa_prefill_debug_collapsed', collapsed ? '1' : '0', 365);
        } else if (action === 'refresh') {
            refresh(button);
        } else if (action === 'load-source') {
            loadSource(button);
        } else if (action === 'force-prefill') {
            mutate(button, 'prefill/force-prefill', message('refill_confirm'));
        } else if (action === 'reset-refill') {
            mutate(button, 'prefill/reset-and-refill', message('refill_confirm'));
        } else if (action === 'clear-storage') {
            mutate(button, 'prefill/clear-storage', message('clear_confirm'));
        } else if (action === 'validation') {
            setCookie('wa_prefill_debug_show_validation', getCookie('wa_prefill_debug_show_validation') === '1' ? '0' : '1', 365);
            window.location.reload();
        }
    }

    function consumeCarrier(carrier) {
        if (!carrier || carrier.dataset.consumed === '1') return;
        carrier.dataset.consumed = '1';
        var root = document.getElementById('prefill-debug-requests');
        if (!root || !carrier.content) return;

        var incoming = carrier.content.querySelector('.pd-request');
        if (!incoming) return;
        var requestId = incoming.getAttribute('data-request-id');
        var existing = root.querySelector('.pd-request[data-request-id="' + requestId + '"]');
        if (existing) {
            var target = existing.querySelector('.pd-request__events');
            incoming.querySelectorAll('.pd-event').forEach(function (event) {
                target.appendChild(document.importNode(event, true));
            });
        } else {
            root.prepend(document.importNode(incoming, true));
        }

        while (root.children.length > maxRequests) root.lastElementChild.remove();
        var empty = panel() && panel().querySelector('.js-prefill-debug-empty');
        if (empty) empty.hidden = root.children.length > 0;
        carrier.remove();
    }

    function init() {
        var bootstrap = document.getElementById('prefill-debug-bootstrap');
        if (!panel() && bootstrap && bootstrap.content) {
            document.body.appendChild(bootstrap.content.cloneNode(true));
            bootstrap.remove();
        }
        var rootPanel = panel();
        if (!rootPanel || rootPanel.dataset.initialized === '1') return;
        rootPanel.dataset.initialized = '1';
        rootPanel.addEventListener('click', handleAction);
        observeColorScheme();

        if (getCookie('wa_prefill_debug_collapsed') === '1') {
            var body = document.getElementById('prefill-debug-body');
            var collapse = rootPanel.querySelector('[data-debug-action="collapse"]');
            if (body) body.hidden = true;
            if (collapse) collapse.textContent = '+';
        }

        document.querySelectorAll('.js-prefill-debug-carrier').forEach(consumeCarrier);
        observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    if (node.matches && node.matches('.js-prefill-debug-carrier')) consumeCarrier(node);
                    if (node.querySelectorAll) node.querySelectorAll('.js-prefill-debug-carrier').forEach(consumeCarrier);
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
