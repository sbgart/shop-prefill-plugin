/**
 * OrderFormManager - модуль для управления формой заказа
 * 
 * Ответственность:
 * - Обработка событий формы заказа (ready, region_changed, details_changed)
 * 
 * Зависимости: ParamsChoiceManager, Logger
 */
class OrderFormManager {
    /**
     * @param {ParamsChoiceManager} paramsChoiceManager - Менеджер выбора параметров
     * @param {Logger} logger - Логгер
     * @param {ZenModeToggle} zenModeToggle - Менеджер Zen Mode
     */
    constructor(paramsChoiceManager, logger, zenModeToggle) {
        this.paramsChoiceManager = paramsChoiceManager;
        this.logger = logger;
        this.zenModeToggle = zenModeToggle;
    }

    /**
     * Инициализирует обработчики событий формы заказа
     */
    init() {
        const that = this;

        // Событие готовности формы
        $(document).on("wa_order_form_ready", function (e, form) {
            that.handleFormReady(form);
        });

        // Событие изменения региона доставки
        $(document).on("wa_order_form_region_changed", function () {
            that.handleRegionChanged();
        });

        // Событие изменения деталей заказа
        $(document).on("wa_order_form_details_changed", function () {
            that.handleDetailsChanged();
        });
    }

    /**
     * Обрабатывает событие готовности формы
     * 
     * @param {Object} form - Объект формы Shop-Script
     */
    handleFormReady(form) {
        this.paramsChoiceManager.renderLink();

        // Инициализируем Zen Mode при готовности формы
        if (this.zenModeToggle) {
            this.zenModeToggle.init();
            this.zenModeToggle.forceDetailSectionVisible();
        }
    }

    /**
     * Обрабатывает событие изменения региона
     */
    handleRegionChanged() {

    }

    /**
     * Обрабатывает событие изменения деталей заказа
     */
    handleDetailsChanged() {
        this.paramsChoiceManager.renderLink();

        if (this.zenModeToggle) {
            this.zenModeToggle.forceDetailSectionVisible();
        }
    }
}
