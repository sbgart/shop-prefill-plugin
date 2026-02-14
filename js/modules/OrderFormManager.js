/**
 * OrderFormManager - модуль для управления формой заказа
 * 
 * Ответственность:
 * - Обработка событий формы заказа (ready, region_changed, details_changed)
 * - Управление валидацией для Zen Mode
 * 
 * Зависимости: ParamsChoiceManager, Logger
 */
class OrderFormManager {
    /**
     * @param {ParamsChoiceManager} paramsChoiceManager - Менеджер выбора параметров
     * @param {Logger} logger - Логгер
     */
    constructor(paramsChoiceManager, logger) {
        this.paramsChoiceManager = paramsChoiceManager;
        this.logger = logger;
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
        this.logger.log("Order form ready, try render link");

        // Проверяем флаг для запуска валидации при ошибках сворачивания
        this.handleZenValidation(form);
    }

    /**
     * Обрабатывает событие изменения региона
     */
    handleRegionChanged() {
        this.paramsChoiceManager.renderLink();
        this.logger.log("Order form region changed, try render link");
    }

    /**
     * Обрабатывает событие изменения деталей заказа
     */
    handleDetailsChanged() {
        this.paramsChoiceManager.renderLink();
        this.logger.log("Order form region changed, try render link");
    }

    /**
     * Обрабатывает валидацию для Zen Mode
     * 
     * @param {Object} form - Объект формы Shop-Script
     */
    handleZenValidation(form) {
        if (window.prefillZenTriggerValidation) {
            this.logger.log("Zen validation flag detected, triggering form validation");

            // Вызываем валидацию напрямую через Shop-Script API
            if (form && typeof form.validate === "function") {
                var $form = form.$wrapper || $("#js-order-form");

                if ($form.length) {
                    // validate($wrapper, render_errors, focus)
                    // render_errors: true - показывать ошибки визуально
                    // focus: true - фокусироваться на первом поле с ошибкой
                    form.validate($form, true, true);
                    this.logger.log("Form validation executed");
                } else {
                    this.logger.warn("Form wrapper not found");
                }
            } else {
                this.logger.warn("Form validate method not available");
            }

            // Сбрасываем флаг
            window.prefillZenTriggerValidation = false;
        }
    }


}
