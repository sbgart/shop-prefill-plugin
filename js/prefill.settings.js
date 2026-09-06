var PrefillSettings = (function () {

    function error(message) {
        return new Error('Prefill Error: ' + message);
    }

    if (!$) throw error('jQuery is required.');

    /**
     * Единая точка показа диалога подтверждения в настройках плагина —
     * замена window.confirm() (см. docs/bugs/native-confirm-dialogs-not-templated.md).
     * Разметка — в templates/actions/settings/blocks/ConfirmDialog.html.
     *
     * @param {string} message Текст вопроса
     * @returns {Promise<boolean>} true при подтверждении, false при отмене/закрытии
     */
    function prefillConfirm(message) {
        var template = document.getElementById('prefill-confirm-dialog-template');
        if (!template || typeof $.waDialog !== 'function') {
            return Promise.resolve(false);
        }

        var root = template.content.cloneNode(true).querySelector('.prefill-confirm-dialog');
        root.querySelector('[data-id="confirm-text"]').textContent = message || '';

        return new Promise(function (resolve) {
            var resolved = false;
            var done = function (value) {
                if (!resolved) {
                    resolved = true;
                    resolve(value);
                }
            };

            $.waDialog({
                html: root,
                onOpen: function ($wrapper, dialog) {
                    $wrapper.on('click', '.js-prefill-confirm-submit', function (e) {
                        e.preventDefault();
                        done(true);
                        dialog.close();
                    });
                },
                onClose: function () {
                    // Крестик, Esc и «отмена» — действие не выполняем
                    done(false);
                }
            });
        });
    }

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
            const onChangeFn = $switcher.data("on-change");

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
                    if (onChangeFn && typeof window[onChangeFn] === 'function') {
                        window[onChangeFn](active, $switcher);
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

            $switcher.waSwitch({
                change: function (active) {
                    $activeInput.val(active ? '1' : '0');
                    if (active) {
                        $editArea.slideDown(200);
                    } else {
                        $editArea.slideUp(200);
                    }
                }
            });
        });

        // Клик по кнопке «Редактировать шаблон» (per-instance custom templates)
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

            self._openTemplateModal({
                group: group,
                title: name,
                initialValue: initialValue,
                onSave: function (value) {
                    $btn.data('template', value).attr('data-template', value);
                    self.$wrapper.find(
                        '.js-prefill-ct-template-input[data-group="' + group + '"][data-instance-id="' + instanceId + '"]'
                    ).val(value);
                }
            });
        });

        // Клик по кнопке «Редактировать» для полей summary_template
        self.$wrapper.on('click', '.js-prefill-edit-summary-template', function (e) {
            e.preventDefault();
            var $btn     = $(this);
            var group    = $btn.data('group');
            var title    = $btn.data('title') || '';
            var $field = $btn.closest('.value');
            var $input = $field.find('.js-prefill-summary-template-input');

            self._openTemplateModal({
                group: group,
                title: title,
                initialValue: $input.val(),
                onSave: function (value) {
                    $input.val(value);
                }
            });
        });
    };

    /**
     * Открывает универсальное модальное окно редактора шаблона.
     * Загружает sidebar (переменные, условия, форматирование) через AJAX.
     *
     * @param {object} options
     * @param {string}   options.group        'customer' | 'delivery' | 'payment'
     * @param {string}   options.title        Заголовок диалога
     * @param {string}   options.initialValue Начальное значение шаблона
     * @param {Function} options.onSave       Колбэк(value) — вызывается при нажатии «Сохранить»
     */
    PrefillSettings.prototype._openTemplateModal = function (options) {
        var group        = options.group;
        var title        = options.title || '';
        var initialValue = options.initialValue || '';
        var onSave       = options.onSave;

        $.post('?module=prefillPluginSettingsTemplateEditor', { group: group })
            .done(function (html) {
                var $wrap = $(html);

                var factoryDefault = $wrap.data('default') || '';

                // Заполняем textarea начальным значением
                $wrap.find('.js-prefill-ct-textarea').val(initialValue);

                var $body   = $wrap.find('.prefill-ct-modal-body');
                var $footer = $wrap.find('.prefill-ct-footer');

                $.waDialog({
                    header:  $('<h4>').text(title),
                    content: $body,
                    footer:  $footer,
                    onResize: function ($dialogWrapper) {
                        var editor = $dialogWrapper.data('prefillZenAce');
                        if (editor) {
                            editor.resize();
                        }
                    },
                    onClose: function (dialog) {
                        prefillZenTemplateAceDestroy(dialog);
                    },
                    onOpen: function ($wrapper, dialog) {
                        $wrapper.addClass('prefill-ct-dialog');
                        // Расширяем диалог — дефолтная ширина контейнера слишком узкая для редактора
                        // Поддерживаем оба варианта разметки диалогов: legacy (.dialog-body) и UI2 (.wa-dialog-body)
                        $wrapper.find('.wa-dialog-body, .dialog-body').addClass('prefill-ct-dialog-body');

                        // Тултипы вешаем на body + fixed: иначе overflow у сайдбара/диалога даёт узкую
                        // «полоску», z-index ниже модалки — контент уезжает под блоки.
                        if (typeof $.fn.waTooltip === 'function') {
                            $wrapper.find('.js-prefill-var-tooltip').waTooltip({
                                allowHTML: true,
                                interactive: true,
                                // Tippy: [задержка показа, скрытия] — не всплывает при быстром проходе мышью
                                delay: [450, 80],
                                maxWidth: 400,
                                placement: 'top',
                                zIndex: 200002,
                                appendTo: function () {
                                    return document.body;
                                },
                                popperOptions: {
                                    strategy: 'fixed',
                                },
                                content: function (reference) {
                                    return prefillBuildVarTooltipHtml($(reference));
                                },
                            });
                        }
                        if (typeof $.fn.waDropdown === 'function') {
                            var $varDropdowns = $wrapper.find('.js-prefill-var-dropdown');

                            // .dropdown-body позиционируется absolute внутри .dropdown, а сайдбар —
                            // overflow:auto (иначе список переменных не влезает по высоте), поэтому
                            // раскрытое меню обрезается краем скролла. waDropdown (wa-content/js/jquery-wa/wa.js)
                            // уже считает высоту от края окна (topOffset/bottomOffset), но не знает про
                            // обрезающий overflow сайдбара — тот же приём, что и для тултипов выше:
                            // position:fixed выводит блок из-под обрезки (ни один предок не задаёт
                            // transform/filter/will-change — новый containing block для fixed не возникает),
                            // координаты — из getBoundingClientRect, он уже в системе координат viewport.
                            // width/min-width переопределяем явно: у fixed-элемента "min-width:100%" из
                            // core CSS считается от viewport, а не от кнопки, иначе меню растянется на экран.
                            $varDropdowns.waDropdown({
                                hover: false,
                                open: function (dropdown) {
                                    var rect = dropdown.$button[0].getBoundingClientRect();
                                    var css = {
                                        position: 'fixed',
                                        left: rect.left + 'px',
                                        width: '200px',
                                        minWidth: rect.width + 'px'
                                    };
                                    if (dropdown.$menu.hasClass('bottom')) {
                                        css.top = 'auto';
                                        css.bottom = (window.innerHeight - rect.top) + 'px';
                                    } else {
                                        css.top = rect.bottom + 'px';
                                        css.bottom = 'auto';
                                    }
                                    dropdown.$menu.css(css);
                                }
                            });

                            // При скролле сайдбара кнопка сдвигается, а зафиксированный дропдаун — нет:
                            // закрываем, чтобы меню не "отрывалось" от кнопки.
                            $wrapper.find('.prefill-ct-sidebar-scroll').on('scroll', function () {
                                $varDropdowns.filter('.is-opened').each(function () {
                                    var instance = $(this).data('dropdown');
                                    if (instance) {
                                        instance.hide();
                                    }
                                });
                            });
                        }

                        prefillZenTemplateAceInit($wrapper);

                        // Tabs: editor / preview
                        (function initPreviewTabs() {
                            var $body = $wrapper.find('.prefill-ct-modal-body');
                            var previewLoadingText = $body.data('preview-loading') || 'Loading…';
                            var previewErrorTitle = $body.data('preview-error-title') || 'Template error';

                            var $tabs = $wrapper.find('.js-prefill-ct-tab');
                            var $panels = $wrapper.find('.js-prefill-ct-tab-panel');
                            var $preview = $wrapper.find('.js-prefill-ct-preview-content');

                            var lastPreviewTemplate = null;
                            var lastPreviewGroup = null;

                            function setActiveTab(tab) {
                                $tabs.closest('li').removeClass('selected');
                                $tabs.filter('[data-tab="' + tab + '"]').closest('li').addClass('selected');

                                $panels.hide();
                                $panels.filter('[data-tab="' + tab + '"]').show();

                                // Ace needs resize when becoming visible (tab switch)
                                if (tab === 'editor') {
                                    var editor = $wrapper.data('prefillZenAce');
                                    if (editor) {
                                        setTimeout(function () { editor.resize(); }, 0);
                                    }
                                }
                            }

                            function renderPreview() {
                                var template = prefillZenTemplateAceGetValue($wrapper);
                                if (template == null) { template = ''; }
                                template = String(template);

                                if (template === lastPreviewTemplate && group === lastPreviewGroup) {
                                    return;
                                }

                                lastPreviewTemplate = template;
                                lastPreviewGroup = group;

                                $preview.html(
                                    '<span class="hint"><i class="icon16 loading"></i> ' + prefillEscapeHtml(previewLoadingText) + '</span>'
                                );

                                $.post('?module=prefillPluginSettingsTemplatePreview', {
                                    group: group,
                                    template: template
                                }).done(function (r) {
                                    if (r && r.status === 'ok' && r.data && r.data.html !== undefined) {
                                        $preview.html(r.data.html);
                                        return;
                                    }

                                    var errors = (r && r.errors) ? r.errors : [];
                                    if (!errors || !errors.length) {
                                        errors = [previewErrorTitle];
                                    }
                                    var errorText = prefillEscapeHtml(errors.join("\n")).replace(/\n/g, '<br />');
                                    $preview.html(
                                        '<div class="prefill-ct-preview-error"><strong>' + prefillEscapeHtml(previewErrorTitle) + '</strong><br />' +
                                        errorText +
                                        '</div>'
                                    );
                                }).fail(function () {
                                    $preview.html(
                                        '<div class="prefill-ct-preview-error"><strong>' + prefillEscapeHtml(previewErrorTitle) + '</strong></div>'
                                    );
                                });
                            }

                            $wrapper.on('click', '.js-prefill-ct-tab', function (e) {
                                e.preventDefault();
                                var tab = $(this).data('tab');
                                setActiveTab(tab);
                                if (tab === 'preview') {
                                    renderPreview();
                                }
                            });

                            setActiveTab('editor');
                        })();

                        // Вставка переменной / сниппета в позицию курсора
                        $wrapper.on('click', '.js-prefill-insert-snippet', function (e) {
                            e.preventDefault();
                            prefillZenTemplateAceInsert($wrapper, $(this).data('snippet'));
                        });

                        // Сохранить
                        $wrapper.find('.js-prefill-ct-save').on('click', function () {
                            var value = prefillZenTemplateAceGetValue($wrapper);
                            if (typeof onSave === 'function') {
                                onSave(value);
                            }
                            dialog.close();
                        });

                        // Отмена
                        $wrapper.find('.js-prefill-ct-cancel').on('click', function () {
                            dialog.close();
                        });

                        // Сбросить к factory default
                        $wrapper.find('.js-prefill-ct-reset').on('click', function (e) {
                            e.preventDefault();
                            prefillZenTemplateAceSetValue($wrapper, factoryDefault);
                        });
                    }
                });
            });
    };

    PrefillSettings.prototype.cssEditor = function () {
        var self = this;

        self.$wrapper.on('click', '.js-prefill-css-edit-btn', function (e) {
            e.preventDefault();
            var $btn    = $(this);
            var code    = $btn.data('prefill-css-code') || '';
            var msgErr  = $btn.data('msg-error') || 'Load error';
            var $value  = $btn.closest('.value');
            var $panel  = $value.find('.js-prefill-css-editor-panel');
            var $loading = $panel.find('.js-prefill-css-loading');
            var $ta     = $panel.find('.js-prefill-css-textarea');

            $btn.hide();
            $panel.show();
            $loading.show();

            $.get('?module=prefillPluginSettingsGetCss', { code: code })
                .done(function (r) {
                    $loading.hide();
                    if (r && r.status === 'ok' && r.data) {
                        var currentCss = r.data.current_css || '';
                        var defaultCss = r.data.default_css || '';
                        var isCustom   = !!r.data.is_custom;

                        $ta.val(currentCss);
                        $panel.data('prefill-default-css', defaultCss);

                        var $status = $panel.find('.js-prefill-css-status');
                        var msgCustom = $btn.data('msg-is-custom') || '';
                        $status.text(isCustom && msgCustom ? msgCustom : '');

                        prefillCssAceInit($panel, currentCss);
                    } else {
                        $panel.find('.js-prefill-css-ace').hide();
                        $ta.show();
                        $panel.find('.js-prefill-css-ace').before(
                            '<p class="errormsg">' + prefillEscapeHtml(msgErr) + '</p>'
                        );
                    }
                })
                .fail(function () {
                    $loading.hide();
                    $ta.show();
                });
        });

        self.$wrapper.on('click', '.js-prefill-css-reset-btn', function (e) {
            e.preventDefault();
            var $panel = $(this).closest('.js-prefill-css-editor-panel');
            prefillConfirm($_('dialog.css_reset.confirm')).then(function (confirmed) {
                if (!confirmed) { return; }
                // Сброс — это очистка переопределений, а не подстановка оригинала (issue-76: override-модель)
                prefillCssAceSetValue($panel, '');
                $panel.find('.js-prefill-css-status').text('');
            });
        });

        self.$wrapper.on('click', '.js-prefill-css-original-toggle', function (e) {
            e.preventDefault();
            var $toggle = $(this);
            var $panel  = $toggle.closest('.js-prefill-css-editor-panel');
            var $original = $panel.find('.js-prefill-css-original-panel');
            var msgShow = $toggle.data('msg-show') || '';
            var msgHide = $toggle.data('msg-hide') || '';

            var willOpen = $original.is(':hidden');
            $original.toggle();
            $toggle.text(willOpen ? msgHide : msgShow);

            if (willOpen && !$original.data('prefill-css-original-ace-inited')) {
                $original.data('prefill-css-original-ace-inited', true);
                prefillCssReadonlyAceInit(
                    $original.find('.js-prefill-css-original-ace'),
                    $panel.data('prefill-default-css') || ''
                );
            }
        });
    };

    PrefillSettings.prototype.debugLogs = function () {
        var self = this;
        var $tab = self.$wrapper.find('#prefill-debug-tab');
        if (!$tab.length) { return; }

        var msgEmpty          = $tab.data('msg-empty')          || 'No entries';
        var msgLoading        = $tab.data('msg-loading')        || 'Loading…';
        var msgError          = $tab.data('msg-error')          || 'Load error';
        var msgClearConfirm   = $tab.data('msg-clear-confirm')  || 'Clear log?';
        var msgLoadMore       = $tab.data('msg-load-more')      || 'Load more';
        var msgStatusLoaded   = $tab.data('msg-status-loaded')  || 'loaded';
        var msgStatusTotal    = $tab.data('msg-status-total')   || 'total in file';

        var currentLevel  = 'all';
        var allEntries    = [];   // newest-first (как возвращает сервер)
        var currentOffset = 0;
        var hasMore       = false;
        var totalInFile   = 0;

        function prefillFormatDate(dateStr) {
            var parts = dateStr ? dateStr.split('-') : [];
            if (parts.length !== 3) { return dateStr || ''; }
            var yy = parts[0].substring(2);
            var mm = parts[1];
            var dd = parts[2].length === 1 ? '0' + parts[2] : parts[2];
            return dd + '.' + mm + '.' + yy;
        }

        var LEVEL_BADGE_COLOR = { debug: 'gray', info: 'blue', warning: 'orange', error: 'red' };

        function renderEntry(entry) {
            var level = entry.level || 'debug';
            var time  = entry.datetime ? entry.datetime.substring(11, 19) : '';
            var date  = entry.datetime ? prefillFormatDate(entry.datetime.substring(0, 10)) : '';

            var msg = prefillEscapeHtml(entry.message);

            // Вторичная строка: IP · user · JS-тег
            var metaParts = [];
            if (entry.ip) { metaParts.push('<span class="prefill-log-entry__ip">' + prefillEscapeHtml(entry.ip) + '</span>'); }
            if (entry.user_id) { metaParts.push('<span class="prefill-log-entry__user">#' + parseInt(entry.user_id, 10) + '</span>'); }
            if (entry.source === 'frontend') {
                metaParts.push('<span class="badge squared prefill-js-tag prefill-log-entry__src-js">JS</span>');
            }
            var sep = '<span class="prefill-log-entry__meta-sep">·</span>';
            var meta = metaParts.length
                ? '<div class="prefill-log-entry__meta">' + metaParts.join(sep) + '</div>'
                : '';

            var ctx = '';
            if (entry.context !== null && entry.context !== undefined) {
                var ctxStr = typeof entry.context === 'object'
                    ? JSON.stringify(entry.context, null, 2)
                    : String(entry.context);
                ctx = '<pre class="prefill-log-entry__context">' + prefillEscapeHtml(ctxStr) + '</pre>';
            }

            var dateHtml = date ? '<span class="prefill-log-entry__date">' + prefillEscapeHtml(date) + '</span>' : '';
            var badgeColor = LEVEL_BADGE_COLOR[level] || 'gray';
            return '<div class="prefill-log-entry prefill-log-entry--' + prefillEscapeHtml(level) + '">'
                + '<div class="prefill-log-entry__rail">'
                +   '<span class="badge squared ' + badgeColor + ' prefill-log-entry__badge">' + prefillEscapeHtml(level.toUpperCase()) + '</span>'
                +   '<span class="prefill-log-entry__time">' + prefillEscapeHtml(time) + '</span>'
                +   dateHtml
                + '</div>'
                + '<div class="prefill-log-entry__body">'
                +   '<div class="prefill-log-entry__message">' + msg + '</div>'
                +   meta
                +   ctx
                + '</div>'
                + '</div>';
        }

        function renderLoadMoreButton() {
            return '<div class="prefill-log-load-more-row">'
                + '<a href="#" class="button light-gray js-prefill-log-load-more">'
                + '<i class="fas fa-angle-down"></i> '
                + prefillEscapeHtml(msgLoadMore)
                + '</a>'
                + '</div>';
        }

        function updateStatus() {
            var $status = self.$wrapper.find('#prefill-log-status');
            var parts = [];
            parts.push(allEntries.length + ' ' + msgStatusLoaded);
            if (hasMore) {
                parts.push(totalInFile + ' ' + msgStatusTotal);
            }
            $status.text(parts.join(' · '));
        }

        function renderLogs() {
            var $entries = self.$wrapper.find('#prefill-log-entries');

            if (!allEntries.length) {
                $entries.html('<div class="prefill-log-state">' + prefillEscapeHtml(msgEmpty) + '</div>');
                updateStatus();
                return;
            }

            var html = '';
            for (var i = 0; i < allEntries.length; i++) {
                html += renderEntry(allEntries[i]);
            }
            if (hasMore) {
                html += renderLoadMoreButton();
            }
            $entries.html(html);
            updateStatus();
        }

        // reset=true — первая загрузка/обновление или смена уровня; reset=false — "загрузить ещё" (только в режиме ALL)
        function loadLogs(reset) {
            var $entries  = self.$wrapper.find('#prefill-log-entries');
            var isAllMode = currentLevel === 'all';

            if (reset) {
                allEntries    = [];
                currentOffset = 0;
                hasMore       = false;
                totalInFile   = 0;
                $entries.html('<div class="prefill-log-state">' + prefillEscapeHtml(msgLoading) + '</div>');
            } else {
                // Кнопка заменяется спиннером на месте — записи не перерисовываются
                $entries.find('.prefill-log-load-more-row').replaceWith(
                    '<div class="prefill-log-load-more-row prefill-log-load-more-row--loading">'
                    + '<i class="fas fa-spinner fa-spin"></i>'
                    + '</div>'
                );
            }

            var scrollTop = reset ? 0 : $entries.scrollTop();
            // Оба режима поддерживают offset; в фильтрованном режиме дополнительно передаём level
            var params = isAllMode
                ? { offset: currentOffset }
                : { level: currentLevel, offset: currentOffset };

            $.get('?module=prefillPluginSettingsReadLogs', params)
                .done(function (r) {
                    if (r && r.status === 'ok' && r.data) {
                        var data  = r.data;
                        var batch = data.entries || [];
                        allEntries    = allEntries.concat(batch);
                        currentOffset += batch.length;
                        hasMore       = !!data.has_more;
                        totalInFile   = data.total || allEntries.length;
                        updateLevelCounts(data.counts);

                        if (reset) {
                            renderLogs();
                        } else {
                            // Дорисовываем только новые записи вместо спиннера
                            var $spinner = $entries.find('.prefill-log-load-more-row--loading');
                            var html = '';
                            for (var i = 0; i < batch.length; i++) {
                                html += renderEntry(batch[i]);
                            }
                            if (hasMore) { html += renderLoadMoreButton(); }
                            $spinner.replaceWith(html);
                            updateStatus();
                            $entries.scrollTop(scrollTop);
                        }
                    } else {
                        $entries.html(
                            '<div class="prefill-log-state prefill-log-state--error">' + prefillEscapeHtml(msgError) + '</div>'
                        );
                    }
                })
                .fail(function () {
                    $entries.html(
                        '<div class="prefill-log-state prefill-log-state--error">' + prefillEscapeHtml(msgError) + '</div>'
                    );
                });
        }

        // Флаг: sub-item активирует tab через .trigger('click') — нельзя сбрасывать уровень в этом случае
        var bypassLogsReset = false;

        function updateLevelCounts(counts) {
            if (!counts) { return; }
            $.each(counts, function (level, count) {
                var $badge = $tab.find('[data-count-level="' + level + '"]');
                if (count > 0) {
                    $badge.text(count).show();
                } else {
                    $badge.hide();
                }
            });
        }

        function updateSidebarActiveState() {
            var $list = $tab.find('[data-level-item]').closest('ul');
            $list.find('[data-level-item]').removeClass('selected');
            if (currentLevel !== 'all') {
                $list.find('[data-level-item="' + currentLevel + '"]').addClass('selected');
            }
        }

        // Подпункты уровней в боковом меню
        self.$wrapper.on('click', '.js-prefill-log-level', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var newLevel = $(this).data('level');
            if (newLevel === currentLevel) { return; }
            currentLevel = newLevel;
            updateSidebarActiveState();
            // Переключаем tab-контент, не сбрасывая уровень в обработчике "logs"
            bypassLogsReset = true;
            $tab.find('[data-tab-trigger="logs"][data-tab-group="debug"]').trigger('click');
            bypassLogsReset = false;
            loadLogs(true);
        });

        // Обновить — сброс + первая страница
        self.$wrapper.on('click', '.js-prefill-log-refresh', function (e) {
            e.preventDefault();
            loadLogs(true);
        });

        // Загрузить ещё — следующая страница старых записей
        self.$wrapper.on('click', '.js-prefill-log-load-more', function (e) {
            e.preventDefault();
            if ($(this).hasClass('disabled')) { return; }
            loadLogs(false);
        });

        // Очистить
        self.$wrapper.on('click', '.js-prefill-log-clear', function (e) {
            e.preventDefault();
            prefillConfirm(msgClearConfirm).then(function (confirmed) {
                if (!confirmed) { return; }
                $.post('?module=prefillPluginSettingsClearLog')
                    .done(function () {
                        allEntries = []; currentOffset = 0; hasMore = false; totalInFile = 0;
                        $tab.find('[data-count-level]').hide();
                        renderLogs();
                    });
            });
        });

        // Уровень лога — авто-сохранение
        self.$wrapper.on('change', '#prefill-log-level-select', function () {
            $.post('?module=prefillPluginSettingsSaveLogLevel', { level: $(this).val() });
        });

        // Клик по пункту "Просмотр логов" — переключиться в режим ALL и перезагрузить если нужно
        self.$wrapper.on('click', '[data-tab-trigger="logs"][data-tab-group="debug"]', function () {
            if (bypassLogsReset) { return; }
            if (currentLevel !== 'all') {
                currentLevel = 'all';
                updateSidebarActiveState();
                loadLogs(true);
            } else if (!allEntries.length && !currentOffset) {
                loadLogs(true);
            }
        });

        // Загружаем при первом клике на вкладку Debug
        self.$wrapper.on('click', '[data-tab-trigger="debug"][data-tab-group="settings"]', function () {
            if (!allEntries.length && !currentOffset) { loadLogs(true); }
        });
    };

    return PrefillSettings;

})()

/**
 * Ace (wa-content/js/ace), как в бэкенде Webasyst для Smarty/HTML.
 * Textarea остаётся скрытой синхронизацией значения для сохранения.
 *
 * @param {jQuery} $wrapper Корень $.waDialog
 */
function prefillZenTemplateAceInit($wrapper) {
    var $ta = $wrapper.find('.js-prefill-ct-textarea');
    var $editorRoot = $wrapper.find('.prefill-ct-editor');
    var $mount = $wrapper.find('.prefill-ct-ace');

    if (typeof ace === 'undefined' || !$mount.length) {
        $editorRoot.addClass('prefill-ct-editor--fallback');
        return;
    }

    ace.config.set('basePath', (window.wa_url || '') + 'wa-content/js/ace/');
    var editor = ace.edit($mount[0]);
    editor.commands.removeCommand('find');

    function applyAceTheme() {
        if (document.documentElement.dataset.theme === 'dark') {
            editor.setTheme('ace/theme/monokai');
        } else {
            editor.setTheme('ace/theme/eclipse');
        }
    }

    applyAceTheme();
    var onWaThemeChange = function () {
        applyAceTheme();
    };
    document.documentElement.addEventListener('wa-theme-change', onWaThemeChange);
    $wrapper.data('prefillZenAceThemeHandler', onWaThemeChange);

    var session = editor.getSession();
    session.setMode('ace/mode/smarty');
    session.setUseWrapMode(true);
    editor.setShowPrintMargin(false);
    editor.renderer.setShowGutter(true);
    if (navigator.appVersion.indexOf('Mac') !== -1) {
        editor.setFontSize(13);
    } else if (navigator.appVersion.indexOf('Linux') !== -1) {
        editor.setFontSize(16);
    } else {
        editor.setFontSize(14);
    }
    editor.setOption('minLines', 12);
    editor.setOption('maxLines', 10000);
    editor.setAutoScrollEditorIntoView(true);

    var initial = $ta.val();
    if (initial == null) {
        initial = '';
    } else {
        initial = String(initial);
    }
    session.setValue(initial);

    session.on('change', function () {
        $ta.val(editor.getValue());
    });
    $ta.val(editor.getValue());

    $wrapper.data('prefillZenAce', editor);

    setTimeout(function () {
        editor.resize();
        editor.focus();
    }, 50);
    setTimeout(function () {
        editor.resize();
    }, 280);
}

/**
 * @param dialog Экземпляр $.waDialog (аргумент onClose)
 */
function prefillZenTemplateAceDestroy(dialog) {
    var $w = dialog && dialog.$wrapper;
    if (!$w || !$w.length) {
        return;
    }
    var themeHandler = $w.data('prefillZenAceThemeHandler');
    if (themeHandler) {
        document.documentElement.removeEventListener('wa-theme-change', themeHandler);
        $w.removeData('prefillZenAceThemeHandler');
    }
    var editor = $w.data('prefillZenAce');
    if (editor) {
        try {
            editor.destroy();
        } catch (ignore) {
        }
        $w.removeData('prefillZenAce');
    }
}

/**
 * @param {jQuery} $wrapper
 * @returns {string}
 */
function prefillZenTemplateAceGetValue($wrapper) {
    var editor = $wrapper.data('prefillZenAce');
    if (editor) {
        return editor.getValue();
    }
    return $wrapper.find('.js-prefill-ct-textarea').val();
}

/**
 * @param {jQuery} $wrapper
 * @param {string} text
 */
function prefillZenTemplateAceSetValue($wrapper, text) {
    var editor = $wrapper.data('prefillZenAce');
    var $ta = $wrapper.find('.js-prefill-ct-textarea');
    var v = text == null ? '' : String(text);
    $ta.val(v);
    if (editor) {
        editor.setValue(v);
        editor.clearSelection();
        editor.navigateFileEnd();
        editor.focus();
    } else {
        $ta.focus();
    }
}

/**
 * @param {jQuery} $wrapper
 * @param {string} text
 */
function prefillZenTemplateAceInsert($wrapper, text) {
    var snippet = text == null ? '' : String(text);
    var editor = $wrapper.data('prefillZenAce');
    if (editor) {
        editor.focus();
        editor.insert(snippet);
        return;
    }
    prefillInsertAtCursor($wrapper.find('.js-prefill-ct-textarea')[0], snippet);
}

/**
 * Экранирование для безопасного вывода в HTML тултипа.
 */
function prefillEscapeHtml(s) {
    if (s === null || s === undefined) {
        return '';
    }
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Разметка HTML-подсказки для waTooltip (переменные редактора Zen).
 */
function prefillBuildVarTooltipHtml($el) {
    var code = $el.data('snippet');
    if (code === undefined || code === null) {
        code = '';
    } else {
        code = String(code);
    }
    var desc = $el.data('description');
    desc = desc !== undefined && desc !== null ? String(desc) : '';
    var example = $el.data('example');
    example = example !== undefined && example !== null ? String(example) : '';
    var exampleCode = $el.data('exampleCode');
    exampleCode = exampleCode !== undefined && exampleCode !== null ? String(exampleCode) : '';
    var exLabel = $el.data('tooltipExampleLabel');
    exLabel = exLabel !== undefined && exLabel !== null ? String(exLabel) : '';

    var parts = [];
    parts.push('<div class="prefill-var-tooltip">');
    parts.push('<div class="prefill-var-tooltip__code"><code>' + prefillEscapeHtml(code) + '</code></div>');
    if (desc) {
        parts.push('<div class="prefill-var-tooltip__desc">' + prefillEscapeHtml(desc) + '</div>');
    }
    if (example && exLabel) {
        parts.push('<div class="prefill-var-tooltip__ex-head">' + prefillEscapeHtml(exLabel) + '</div>');
        parts.push('<div class="prefill-var-tooltip__ex">' + prefillEscapeHtml(example) + '</div>');
    } else if (example) {
        parts.push('<div class="prefill-var-tooltip__ex">' + prefillEscapeHtml(example) + '</div>');
    }
    if (exampleCode) {
        parts.push('<div class="prefill-var-tooltip__ex-code"><code>' + prefillEscapeHtml(exampleCode) + '</code></div>');
    }
    parts.push('</div>');
    return parts.join('');
}

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

/**
 * Инициализирует ACE-редактор в режиме CSS внутри $container.
 * @param {jQuery} $container Панель редактора (.js-prefill-css-editor-panel)
 * @param {string} initialValue Начальное содержимое
 */
function prefillCssAceInit($container, initialValue) {
    var $ta    = $container.find('.js-prefill-css-textarea');
    var $mount = $container.find('.js-prefill-css-ace');

    if (typeof ace === 'undefined' || !$mount.length) {
        $ta.show().val(initialValue);
        return;
    }

    ace.config.set('basePath', (window.wa_url || '') + 'wa-content/js/ace/');
    var editor = ace.edit($mount[0]);
    editor.commands.removeCommand('find');

    function applyTheme() {
        editor.setTheme(
            document.documentElement.dataset.theme === 'dark'
                ? 'ace/theme/monokai'
                : 'ace/theme/eclipse'
        );
    }
    applyTheme();
    var themeHandler = function () { applyTheme(); };
    document.documentElement.addEventListener('wa-theme-change', themeHandler);
    $container.data('prefillCssAceThemeHandler', themeHandler);

    var session = editor.getSession();
    session.setMode('ace/mode/css');
    session.setUseWrapMode(true);
    editor.setShowPrintMargin(false);
    editor.renderer.setShowGutter(true);
    editor.setAutoScrollEditorIntoView(false);

    var v = initialValue == null ? '' : String(initialValue);
    session.setValue(v);
    editor.clearSelection();
    editor.navigateFileStart();

    session.on('change', function () { $ta.val(editor.getValue()); });

    $container.data('prefillCssAce', editor);
    setTimeout(function () { editor.resize(); editor.focus(); }, 50);
}

/**
 * Инициализирует read-only ACE-редактор (mode: css) для показа оригинального frontend.css.
 * Отдельный инстанс от override-редактора — синхронизация с textarea не нужна.
 *
 * @param {jQuery} $mount Контейнер для монтирования (.js-prefill-css-original-ace)
 * @param {string} value Содержимое оригинального файла
 */
function prefillCssReadonlyAceInit($mount, value) {
    if (typeof ace === 'undefined' || !$mount.length) {
        $mount.text(value == null ? '' : String(value));
        return;
    }

    ace.config.set('basePath', (window.wa_url || '') + 'wa-content/js/ace/');
    var editor = ace.edit($mount[0]);
    editor.commands.removeCommand('find');
    editor.setReadOnly(true);
    editor.renderer.$cursorLayer.element.style.display = 'none';

    function applyTheme() {
        editor.setTheme(
            document.documentElement.dataset.theme === 'dark'
                ? 'ace/theme/monokai'
                : 'ace/theme/eclipse'
        );
    }
    applyTheme();
    document.documentElement.addEventListener('wa-theme-change', applyTheme);

    var session = editor.getSession();
    session.setMode('ace/mode/css');
    session.setUseWrapMode(true);
    editor.setShowPrintMargin(false);
    editor.renderer.setShowGutter(true);
    editor.setHighlightActiveLine(false);

    session.setValue(value == null ? '' : String(value));
    editor.clearSelection();
    editor.navigateFileStart();

    setTimeout(function () { editor.resize(); }, 50);
}

/**
 * @param {jQuery} $container
 * @param {string} value
 */
function prefillCssAceSetValue($container, value) {
    var v      = value == null ? '' : String(value);
    var editor = $container.data('prefillCssAce');
    var $ta    = $container.find('.js-prefill-css-textarea');
    $ta.val(v);
    if (editor) {
        editor.setValue(v);
        editor.clearSelection();
        editor.navigateFileStart();
        editor.focus();
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
    settings.cssEditor();
    settings.debugLogs();
}

document.addEventListener('prefill:storefront-content-loaded', function (event) {
    const container = event.detail && event.detail.container ? event.detail.container : null;
    if (!container) { return; }
    initPrefillSettings(container);
});


function prefillUpdateStorefrontStatus(active) {
    var component = document.querySelector('prefill-storefront-select[show-status="true"]');
    if (!component) { return; }
    var code = $(component).find('select[data-id="storefront-select"]').val();
    if (!code) { return; }
    component.updateOptionStatus(code, active);
}
