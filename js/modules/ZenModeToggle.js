/**
 * ZenModeToggle - модуль для управления сворачиванием секций чекаута (Zen Mode)
 *
 * Ответственность:
 * - Обработка кликов на кнопках "Свернуть" / "Изменить"
 * - Валидация секций перед сворачиванием
 * - Управление cookies состояния групп
 * - Обновление формы заказа через waOrder API
 *
 * Зависимости: window.waOrder (Shop-Script API)
 */
class ZenModeToggle {
  /**
   * @param {DialogManager} dialogManager - Менеджер диалоговых окон
   * @param {Object} messages - Объект с сообщениями локализации
   * @param {Logger} logger - Экземпляр логгера
   */
  constructor(dialogManager, messages, logger) {
    this.dialogManager = dialogManager;
    this.messages = messages || {};
    this.logger = logger;
    this.initialized = false;

    // Маппинг групп → секций чекаута
    this.groupSections = {
      customer: ["auth"],
      delivery: ["region", "shipping", "details"],
      payment: ["payment"],
    };
  }

  /**
   * Инициализирует обработчик событий
   * Защищён от повторной инициализации
   */
  init() {
    if (this.initialized) {
      return;
    }
    this.initialized = true;

    // Делегирование событий на document для обработки динамически добавленных элементов
    document.addEventListener("click", this.handleClick.bind(this));
  }

  /**
   * Обрабатывает клик на кнопках toggle
   *
   * @param {Event} e - Событие клика
   */
  handleClick(e) {
    var btn = e.target.closest(".js-prefill-zen-toggle");
    if (!btn) return;

    e.preventDefault();

    var group = btn.dataset.group;
    var action = btn.dataset.action;

    if (action === "expand") {
      this.expandGroup(group);
    } else {
      this.collapseGroup(group);
    }
  }

  /**
   * Разворачивает группу секций
   *
   * @param {string} group - Имя группы (customer, delivery, payment)
   */
  expandGroup(group) {
    var cookieName = "prefill_zen_" + group;

    // Устанавливаем cookie состояния
    document.cookie = cookieName + "=expanded; path=/; SameSite=Lax";

    // Обновляем форму заказа
    if (window.waOrder && window.waOrder.form) {
      if (this.logger) {
        this.logger.info("User expanded the " + group + " group section");
      }
      window.waOrder.form.update();
    }
  }

  /**
   * Сворачивает группу секций с предварительной валидацией
   *
   * @param {string} group - Имя группы
   */
  collapseGroup(group) {
    if (!window.waOrder || !window.waOrder.form) {
      return;
    }

    var form = window.waOrder.form;
    var sections = this.getSectionsForGroup(group);

    // Валидируем секции группы
    var hasErrors = this.validateSections(form, sections);

    if (!hasErrors) {
      var cookieName = "prefill_zen_" + group;

      // Валидация успешна → удаляем cookie и обновляем форму (бэкенд при ошибках снова проставит expanded)
      document.cookie = cookieName + "=; path=/; SameSite=Lax; max-age=0";

      if (this.logger) {
        this.logger.info("User collapsed the " + group + " group section");
      }

      form.update();
    } else {
      if (this.logger) {
        this.logger.info("User attempted to collapse the " + group + " group section, but validation failed");
      }
      // Валидация не прошла → показываем модальное окно с warning
      this.showValidationErrorDialog();
    }
  }

  /**
   * Показывает диалог с ошибкой валидации
   */
  showValidationErrorDialog() {
    if (!this.dialogManager) return;

    const title = this.messages.validation_error_title || "";
    const message = this.messages.validation_error_message || "Validation error";
    const buttonText = this.messages.validation_error_button || "OK";

    // Устанавливаем заголовок
    this.dialogManager.setHeader("zen-validation-error", title);

    // Используем HTML для контента диалога
    const content = `
            <div class="prefill-warning">
                <p class="prefill-warning__text">${message}</p>
                <button class="button prefill-warning__btn js-close-dialog">${buttonText}</button>
            </div>
        `;

    this.dialogManager.showDialog("zen-validation-error", content).then((dialog) => {
      // Добавляем обработчик на кнопку OK внутри диалога
      const okBtn = dialog.querySelector(".js-close-dialog");
      if (okBtn) {
        okBtn.addEventListener("click", () => this.dialogManager.closeDialog(dialog));
      }
    });
  }

  /**
   * Возвращает список секций для группы
   *
   * @param {string} group - Имя группы
   * @returns {Array<string>} Массив имён секций
   */
  getSectionsForGroup(group) {
    return this.groupSections[group] || [];
  }

  /**
   * Валидирует секции формы
   *
   * @param {Object} form - Объект формы waOrder
   * @param {Array<string>} sections - Массив имён секций для валидации
   * @returns {boolean} true если есть ошибки, false если всё ОК
   */
  validateSections(form, sections) {
    var hasErrors = false;

    sections.forEach(function (sectionName) {
      var section = form.sections[sectionName];

      if (section && typeof section.getData === "function") {
        var sectionData = section.getData({
          clean: false,
          render_errors: true,
        });

        if (sectionData.errors && sectionData.errors.length > 0) {
          hasErrors = true;
        }
      }
    });

    return hasErrors;
  }

  /**
   * Принудительно показывает секцию деталей заказа, убирая style="display:none"
   * Это необходимо для корректной работы Zen Mode в некоторых сценариях
   */
  forceDetailSectionVisible() {
    const $section = $("#wa-step-details-section");
    if ($section.length && $section.attr("style") && $section.attr("style").indexOf("display") !== -1) {
      // console.log("ZenMode: Removing display:none from #wa-step-details-section");
      $section.removeAttr("style");
    }
  }
}

// Экспорт класса в глобальную область для использования в других модулях
window.ZenModeToggle = ZenModeToggle;
