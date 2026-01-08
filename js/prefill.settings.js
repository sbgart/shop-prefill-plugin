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
            $switcher.iButton({
                labelOn: '',
                labelOff: '',
                className: 'mini',
            });
        })

    }

    PrefillSettings.prototype.storefrontSelect = function (module, params) {
        if (!params) params = {};
        if (!module) module = 'prefillPluginSettingsStorefront';

        const self = this;

        const $storefrontSelect = self.$wrapper.find('[data-id="storefront-select"]');
        const $storefrontContent = self.$wrapper.find('[data-id="storefront-content"]');

        function renderActiveContent() {
            $storefrontContent.html('<i class="icon16 loading"></i>');

            const selectedStorefront = $storefrontSelect.find(':selected');

            const storefrontCode = selectedStorefront.data('code');

            $.post('?module=' + module, Object.assign({code: storefrontCode}, params))
                .done(function (data) {
                    $storefrontContent.html(data);

                    $(self.$wrapper).trigger('shop_minorder_storefront_settings_loaded');
                });
        }

        renderActiveContent();
        $storefrontSelect.on('change', renderActiveContent);

    }

    PrefillSettings.prototype.tabs = function () {
        const $tabs = this.$wrapper.find('[data-type*="tabs"]');

        $tabs.each(function () {
            const $tab = $(this);

            const $tabTriggers = $tab.find('[data-tab-trigger]');

            function showActiveTabContent(tab) {
                $tabTriggers.each(function () {
                    $(this).parent().removeClass('selected')
                })

                const $tabTrigger = $tab.find('[data-tab-trigger="' + tab + '"]');
                $tabTrigger.parent().addClass('selected');

                const $tabContent = $tab.find('[data-tab-content]');
                $tabContent.hide();

                const $selectedTabContent = $tab.find('[data-tab-content="' + tab + '"]');
                $selectedTabContent.show();

            }

            showActiveTabContent('general');

            $tabTriggers.on('click', function (event) {
                event.preventDefault();

                const tab = $(event.target).data('tab-trigger');
                showActiveTabContent(tab);
            })
        })
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

    return PrefillSettings;

})()