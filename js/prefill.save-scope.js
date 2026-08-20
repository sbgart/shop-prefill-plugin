/**
 * Выбор области сохранения настроек плагина.
 *
 * Web-компонент витрин кеширует загруженные блоки и прячет их через display:none,
 * а браузер отправляет скрытые поля наравне с видимыми — в POST уезжают все витрины,
 * которые администратор успел открыть, и каждая перезаписывается снимком на момент
 * её загрузки (см. issue-78). Поэтому перед сохранением спрашиваем, что именно
 * сохранять, и при выборе «только текущую» отключаем поля остальных контейнеров.
 */
(function () {
    "use strict";

    var SCOPE_CURRENT = "current";
    var SCOPE_ALL = "all";
    var TEMPLATE_ID = "prefill-save-scope-dialog-template";
    var MAX_LISTED_STOREFRONTS = 5;

    /**
     * Диалог выбора области сохранения.
     *
     * Разметка и все тексты — в templates/actions/settings/blocks/SaveScopeDialog.html,
     * здесь только подстановка данных, известных лишь на клиенте.
     */
    function PrefillSaveScopeDialog() {
    }

    /**
     * @param {{label: string}} current Текущая (видимая) витрина
     * @param {Array<{label: string}>} others Прочие открытые витрины
     * @returns {Promise<string|null>} SCOPE_CURRENT | SCOPE_ALL | null (отмена)
     */
    PrefillSaveScopeDialog.prototype.show = function (current, others) {
        var self = this;

        return new Promise(function (resolve) {
            var resolved = false;
            var done = function (value) {
                if (!resolved) {
                    resolved = true;
                    resolve(value);
                }
            };

            $.waDialog({
                html: self.render(current, others),
                onOpen: function ($wrapper, dialog) {
                    $wrapper.on("click", ".js-prefill-save-scope-submit", function (event) {
                        event.preventDefault();
                        var checked = $wrapper.find('input[name="prefill-save-scope"]:checked').val();
                        done(checked || SCOPE_CURRENT);
                        dialog.close();
                    });
                },
                onClose: function () {
                    // Крестик, Esc и «отмена» — сохранение не запускаем
                    done(null);
                }
            });
        });
    };

    /**
     * @returns {HTMLElement} Готовая к показу копия шаблона диалога
     */
    PrefillSaveScopeDialog.prototype.render = function (current, others) {
        var template = document.getElementById(TEMPLATE_ID);
        var root = template.content.cloneNode(true).querySelector(".prefill-save-scope-dialog");
        var all = [current].concat(others);

        setText(root.querySelector('[data-id="current-label"]'), current.label);
        replaceNumber(root.querySelector('[data-id="all-label"]'), all.length);
        this.renderList(root.querySelector('[data-id="storefront-list"]'), all);

        return root;
    };

    /**
     * Заполняет список витрин, клонируя заготовки строк из шаблона.
     */
    PrefillSaveScopeDialog.prototype.renderList = function (list, storefronts) {
        var item_template = list.querySelector('[data-id="storefront-item"]');
        var more_template = list.querySelector('[data-id="more-item"]');
        var listed = storefronts.slice(0, MAX_LISTED_STOREFRONTS);
        var rest = storefronts.length - listed.length;

        list.textContent = "";

        listed.forEach(function (storefront) {
            var item = item_template.cloneNode(true);
            setText(item, storefront.label);
            list.appendChild(item);
        });

        if (rest > 0) {
            var more = more_template.cloneNode(true);
            replaceNumber(more, rest);
            list.appendChild(more);
        }
    };

    /**
     * Перехватывает сабмит формы настроек и ограничивает набор отправляемых витрин.
     */
    function PrefillSaveScope(form, wrapper) {
        this.form = form;
        this.wrapper = wrapper;
        this.dialog = new PrefillSaveScopeDialog();
        this.pending_scope = null;
        this.disabled_fields = [];
    }

    PrefillSaveScope.prototype.init = function () {
        // Обработчик ядра (shop/js/backend/plugins.js) висит на самой форме,
        // поэтому перехватываем раньше — в фазе перехвата на документе
        document.addEventListener("submit", this.onSubmit.bind(this), true);
    };

    PrefillSaveScope.prototype.onSubmit = function (event) {
        if (event.target !== this.form) {
            return;
        }

        // Повторный сабмит после выбора в диалоге — применяем выбор и пропускаем событие дальше
        if (this.pending_scope) {
            if (this.pending_scope === SCOPE_CURRENT) {
                this.disableHiddenStorefronts();
            }
            this.pending_scope = null;
            this.finishSubmit();
            return;
        }

        var storefronts = this.collectStorefronts();
        if (!storefronts.current || storefronts.others.length === 0) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        var self = this;
        this.dialog.show(storefronts.current, storefronts.others).then(function (scope) {
            if (!scope) {
                return;
            }
            self.pending_scope = scope;
            self.form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
        });
    };

    /**
     * Досылает форму, если её никто не перехватил, и снимает временный disabled.
     *
     * Обработчик ядра отменяет событие и шлёт форму AJAX-ом, но синтетический submit
     * сам по себе нативную отправку не запускает — без этого при отсутствии обработчика
     * (страница открыта не через SPA плагинов) сохранение молча не произошло бы.
     */
    PrefillSaveScope.prototype.finishSubmit = function () {
        var self = this;
        var needs_native_submit = false;

        this.form.addEventListener("submit", function fallback(event) {
            self.form.removeEventListener("submit", fallback);
            needs_native_submit = !event.defaultPrevented;
        });

        setTimeout(function () {
            if (needs_native_submit) {
                self.form.submit();
            }
            // Восстанавливаем только после отправки: форма сериализуется синхронно
            self.restoreDisabledFields();
        }, 0);
    };

    /**
     * @returns {{current: ?Object, others: Array}} Открытые витрины: видимая и скрытые
     */
    PrefillSaveScope.prototype.collectStorefronts = function () {
        var result = { current: null, others: [] };
        var containers = this.wrapper.querySelectorAll("[data-storefront-code]");

        Array.prototype.forEach.call(containers, function (container) {
            var storefront = {
                code: container.getAttribute("data-storefront-code"),
                container: container
            };
            storefront.label = this.getStorefrontLabel(storefront.code);

            if (container.style.display === "none") {
                result.others.push(storefront);
            } else {
                result.current = storefront;
            }
        }, this);

        return result;
    };

    PrefillSaveScope.prototype.getStorefrontLabel = function (code) {
        var select = document.querySelector('prefill-storefront-select select[data-id="storefront-select"]');
        var option = select ? select.querySelector('option[data-code="' + escapeSelector(code) + '"]') : null;

        return (option && option.dataset.label) || code;
    };

    PrefillSaveScope.prototype.disableHiddenStorefronts = function () {
        var self = this;
        this.collectStorefronts().others.forEach(function (storefront) {
            var container = storefront.container;
            var fields = container.querySelectorAll("input, select, textarea");
            Array.prototype.forEach.call(fields, function (field) {
                // Поля, отключённые самой формой, трогать нельзя — восстанавливаем только свои
                if (!field.disabled) {
                    field.disabled = true;
                    self.disabled_fields.push(field);
                }
            });
        });
    };

    PrefillSaveScope.prototype.restoreDisabledFields = function () {
        this.disabled_fields.forEach(function (field) {
            field.disabled = false;
        });
        this.disabled_fields = [];
    };

    function setText(element, value) {
        element.textContent = String(value == null ? "" : value);
    }

    /**
     * Подставляет число в готовую строку локали («…(%d)», «и ещё %d»).
     */
    function replaceNumber(element, number) {
        element.textContent = element.textContent.replace("%d", number);
    }

    function escapeSelector(value) {
        return String(value == null ? "" : value).replace(/["\\]/g, "\\$&");
    }

    $(function () {
        var form = document.getElementById("plugins-settings-form");
        var wrapper = document.getElementById("prefill-storefront-content");

        var has_template = !!document.getElementById(TEMPLATE_ID);

        if (form && wrapper && has_template && typeof $.waDialog === "function") {
            new PrefillSaveScope(form, wrapper).init();
        }
    });
})();
