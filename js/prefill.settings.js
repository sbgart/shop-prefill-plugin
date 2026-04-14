var PrefillSettings = (function () {

    function error(message) {
        return new Error('Prefill Error: ' + message);
    }

    if (!$) throw error('jQuery is required.');

    PrefillSettings = function (wrapper) {
        const $wrapper = $(wrapper);

        if ($wrapper.length === 0) throw error('Element with selector ' + wrapper + ' does not exist.')

        this.$wrapper = $wrapper;
    }

    PrefillSettings.prototype.switcher = function () {
        const $switchers = this.$wrapper.find('[data-type*="switcher"]');
        $switchers.each(function () {
            const $switcher = $(this);
            const target = $switcher.data("for");
            const $target = target ? $switcher.closest(".field").find('[data-id="' + target + '"]') : $();

            if ($target.length > 0) {
                const isChecked = $switcher.find('input[type="checkbox"]').is(":checked");
                if (isChecked) {
                    $target.show();
                } else {
                    $target.hide();
                }
            }

            $switcher.waSwitch({
                change: function (active) {
                    if ($target.length > 0) {
                        if (active) {
                            $target.slideDown(200);
                        } else {
                            $target.slideUp(200);
                        }
                    }
                },
            });
        });

    }

    PrefillSettings.prototype.tabs = function () {
        const $tabs = this.$wrapper.find('[data-type*="tabs"]');

        $tabs.each(function () {
            const $tab = $(this);
            const group = $tab.data('tab-group');

            // Scope triggers and contents to the same group to prevent cross-contamination with nested tab sets
            const groupAttr = group ? '[data-tab-group="' + group + '"]' : '';
            const $tabTriggers = $tab.find('[data-tab-trigger]' + groupAttr);
            const $tabContents = $tab.find('[data-tab-content]' + groupAttr);

            // Use first trigger as default tab (no hardcoded name)
            const defaultTab = $tab.data('tab-default') || ($tabTriggers.first().data('tab-trigger') || 'general');

            function showActiveTabContent(tab) {
                $tabTriggers.each(function () {
                    $(this).parent().removeClass('selected');
                });

                $tab.find('[data-tab-trigger="' + tab + '"]' + groupAttr).parent().addClass('selected');
                $tabContents.hide();
                $tabContents.filter('[data-tab-content="' + tab + '"]').show();
            }

            showActiveTabContent(defaultTab);

            $tabTriggers.on('click', function (event) {
                event.preventDefault();
                // Use closest() to handle clicks on child elements (e.g. <span> inside <a>)
                const tab = $(event.target).closest('[data-tab-trigger]').data('tab-trigger');
                if (tab) showActiveTabContent(tab);
            });
        });
    }

    PrefillSettings.prototype.collapse = function () {
        const self = this;

        const $collapses = self.$wrapper.find('[data-type*="collapse"]');

        $collapses.each(function () {
            const $collapse = $(this);

            const selector = $collapse.data('for');

            const $collapsable = self.$wrapper.find('[data-id="' + selector + '"]');

            $collapsable.hide();

            if ($collapse.is(':checked')) $collapsable.show();

            $collapse.on('click change', function (e) {
                e.preventDefault();

                $collapsable.toggle("fast");
            })
        })
    }

    PrefillSettings.prototype.colorPicker = function () {
        this.$wrapper.find('[data-color-picker]').each(function () {
            var $picker = $(this);
            var $text = $picker.parent().find('[data-color-text]');

            $picker.on('input', function () { $text.val($picker.val()); });
            $text.on('change input', function () {
                var val = $text.val().trim();
                if (/^#[0-9a-fA-F]{3}$|^#[0-9a-fA-F]{6}$/.test(val)) {
                    $picker.val(val);
                }
            });
        });
    }

    // Показывает/скрывает поле `data-id="<target>"` в зависимости от значения:
    //  — <select data-reveal-target="…" data-reveal-value="custom">
    //  — <div data-radio-reveal="…"> (обёртка вокруг radio-группы)
    PrefillSettings.prototype.valueReveal = function () {
        const self = this;

        self.$wrapper.find('select[data-reveal-target]').each(function () {
            const $select = $(this);
            const target = $select.data('reveal-target');
            const revealOn = String($select.data('reveal-value'));
            const $target = $select.closest('.fields-group').find('[data-id="' + target + '"]');

            const toggle = function () {
                if ($select.val() === revealOn) { $target.show(); }
                else { $target.hide(); }
            };
            toggle();
            $select.on('change', toggle);
        });

        self.$wrapper.find('[data-radio-reveal]').each(function () {
            const $group = $(this);
            const target = $group.data('radio-reveal');
            const $target = $group.closest('.fields-group').find('[data-id="' + target + '"]');

            const toggle = function () {
                const val = $group.find('input[type="radio"]:checked').val();
                if (val === 'custom') { $target.show(); }
                else { $target.hide(); }
            };
            toggle();
            $group.find('input[type="radio"]').on('change', toggle);
        });
    }

    PrefillSettings.prototype.sortable = function () {
        const self = this;

        const $sortables = self.$wrapper.find('[data-type*="sortable"]');

        $sortables.each(function () {
            const $sortable = $(this);
            $sortable.sortable({
                distance: 5,
                handle: '.sort',
                items: '>*:not(.unsortable)',
                opacity: 0.75,
                tolerance: 'pointer',
                start: function (event, ui) {
                    $sortable.sortable("refresh");
                    $sortable.sortable({
                        cancel: ".unsortable"
                    });
                },
                update: function (event, ui) {
                    $sortable.trigger('sortable_sort_change@prefill');
                }
            });
        })
    }

    /**
     * Кастомные шаблоны Zen Mode для конкретных инстансов доставки / оплаты.
     * Инициализирует switcher-ы строк и модальное окно редактора шаблонов.
     */
    PrefillSettings.prototype.customTemplates = function () {
        var self = this;

        // Switcher активации кастомного шаблона для инстанса
        self.$wrapper.find('.js-prefill-ct-switcher').each(function () {
            var $switcher = $(this);
            var $row = $switcher.closest('.prefill-custom-template-row');
            var $activeInput = $row.find('.js-prefill-ct-active-input');
            var $editArea = $row.find('.js-prefill-ct-edit');
            var $hint = $row.find('.js-prefill-ct-hint');

            $switcher.waSwitch({
                change: function (active) {
                    $activeInput.val(active ? '1' : '0');
                    if (active) {
                        $editArea.slideDown(200);
                        $hint.slideUp(200);
                    } else {
                        $editArea.slideUp(200);
                        $hint.slideDown(200);
                    }
                }
            });
        });

        // Клик по кнопке «Редактировать шаблон»
        self.$wrapper.on('click', '.js-prefill-edit-template', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var instanceId = String($btn.data('instance-id'));
            var group      = $btn.data('group');
            var name       = $btn.data('instance-name') || '';
            var tmpl       = $btn.data('template') || '';
            var groupTmpl  = $btn.data('group-template') || '';

            // Первое открытие (кастомный шаблон пустой) — начать с общего шаблона группы
            var initialValue = tmpl || groupTmpl;

            self._openTemplateModal($btn, instanceId, group, name, initialValue);
        });
    };

    /**
     * Открывает модальное окно редактора шаблона.
     * Загружает содержимое через AJAX (?module=prefillPluginSettingsTemplateEditor),
     * Smarty рендерит переменные и локаль на сервере, JS вставляет результат в $.waDialog.
     *
     * @param {jQuery} $btn         Кнопка «Редактировать», хранит data-template
     * @param {string} instanceId   ID инстанса плагина (ключ в custom_templates)
     * @param {string} group        'delivery' | 'payment'
     * @param {string} name         Название инстанса (для заголовка)
     * @param {string} initialValue Начальное значение textarea
     */
    PrefillSettings.prototype._openTemplateModal = function ($btn, instanceId, group, name, initialValue) {
        var self = this;

        $.post('?module=prefillPluginSettingsTemplateEditor', { group: group })
            .done(function (html) {
                var $wrap = $(html);

                var factoryDefault = $wrap.data('default') || '';
                var titleTpl = $wrap.data('title-template') || '%s';
                var title    = titleTpl.replace('%s', name);

                // Заполняем textarea начальным значением
                $wrap.find('.js-prefill-ct-textarea').val(initialValue);

                var $body   = $wrap.find('.prefill-ct-modal-body');
                var $footer = $wrap.find('.prefill-ct-footer');

                $.waDialog({
                    header:  $('<h4>').text(title),
                    content: $body,
                    footer:  $footer,
                    onOpen: function ($wrapper, dialog) {
                        $wrapper.addClass('prefill-ct-dialog');
                        // Расширяем диалог — дефолтная ширина контейнера слишком узкая для редактора
                        // Поддерживаем оба варианта разметки диалогов: legacy (.dialog-body) и UI2 (.wa-dialog-body)
                        $wrapper.find('.wa-dialog-body, .dialog-body').addClass('prefill-ct-dialog-body');
                        // Вставка переменной / сниппета в позицию курсора
                        $wrapper.on('click', '.js-prefill-insert-var', function (e) {
                            e.preventDefault();
                            prefillInsertAtCursor(
                                $wrapper.find('.js-prefill-ct-textarea')[0],
                                $(this).data('snippet')
                            );
                        });

                        // Сохранить
                        $wrapper.find('.js-prefill-ct-save').on('click', function () {
                            var newTemplate = $wrapper.find('.js-prefill-ct-textarea').val();

                            $btn.data('template', newTemplate).attr('data-template', newTemplate);

                            self.$wrapper.find(
                                '.js-prefill-ct-template-input[data-group="' + group + '"][data-instance-id="' + instanceId + '"]'
                            ).val(newTemplate);

                            dialog.close();
                        });

                        // Отмена
                        $wrapper.find('.js-prefill-ct-cancel').on('click', function () {
                            dialog.close();
                        });

                        // Сбросить к factory default
                        $wrapper.find('.js-prefill-ct-reset').on('click', function (e) {
                            e.preventDefault();
                            $wrapper.find('.js-prefill-ct-textarea').val(factoryDefault).focus();
                        });
                    }
                });
            });
    };

    return PrefillSettings;

})()

/**
 * Вставляет text в позицию курсора textarea (или в конец, если нет фокуса).
 */
function prefillInsertAtCursor(el, text) {
    if (!el) { return; }
    el.focus();
    if (el.selectionStart !== undefined) {
        var start = el.selectionStart;
        var end   = el.selectionEnd;
        el.value = el.value.substring(0, start) + text + el.value.substring(end);
        el.selectionStart = el.selectionEnd = start + text.length;
    } else {
        el.value += text;
    }
}

function initPrefillSettings(container) {
    const settings = new PrefillSettings(container);
    settings.switcher();
    settings.tabs();
    settings.collapse();
    settings.sortable();
    settings.colorPicker();
    settings.valueReveal();
    settings.customTemplates();
}

document.addEventListener('prefill:storefront-content-loaded', function (event) {
    const container = event.detail && event.detail.container ? event.detail.container : null;
    if (!container) { return; }
    initPrefillSettings(container);
});

document.addEventListener('prefill:storefront-content-shown', function (event) {
    const container = event.detail && event.detail.container ? event.detail.container : null;
    if (!container) { return; }
    initPrefillSettings(container);
});
