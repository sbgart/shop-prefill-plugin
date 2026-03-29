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

document.addEventListener('prefill:storefront-content-loaded', function (event) {
    const container = event.detail && event.detail.container ? event.detail.container : null;
    if (!container) {
        return;
    }

    const settings = new PrefillSettings(container);
    settings.switcher();
    settings.tabs();
    settings.collapse();
    settings.sortable();
});

document.addEventListener('prefill:storefront-content-shown', function (event) {
    const container = event.detail && event.detail.container ? event.detail.container : null;
    if (!container) {
        return;
    }

    const settings = new PrefillSettings(container);
    settings.switcher();
    settings.tabs();
    settings.collapse();
    settings.sortable();
});