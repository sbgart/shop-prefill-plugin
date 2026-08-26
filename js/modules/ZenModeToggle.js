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

    // data-blocked-by ставит сервер на каждом рендере: клиентская валидация не видит
    // серверных ошибок (waEmailValidator, забаненный адрес, чужой контакт, кончившийся
    // товар). Пока оформление ими заблокировано, бессмысленны оба направления: свернуть
    // значит спрятать сообщение об ошибке, развернуть — показать пустоту, потому что
    // секцию ядро в этом запросе не отрисовало.
    var blockedBy = btn.dataset.blockedBy;

    if (action === "expand") {
      this.expandGroup(group, blockedBy);
    } else {
      this.collapseGroup(group, blockedBy);
    }
  }

  /**
   * Разворачивает группу секций
   *
   * @param {string} group - Имя группы (customer, delivery, payment)
   * @param {string} [blockedBy] - Группа с серверной ошибкой, блокирующей оформление
   */
  expandGroup(group, blockedBy) {
    // Разворачивать нечего: ядро короткозамкнуло конвейер и секции этой группы не
    // отрисовало. Покупатель увидел бы пустоту вместо своих данных — а они целы,
    // их вернёт эхо-кэш, как только ошибка выше будет исправлена.
    if (blockedBy) {
      if (this.logger) {
        this.logger.info("User attempted to expand the " + group + " group section while checkout is blocked by " + blockedBy);
      }
      this.showCheckoutBlockedDialog(blockedBy);
      return;
    }

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
   * @param {string} [blockedBy] - Группа с серверной ошибкой, блокирующей оформление
   */
  collapseGroup(group, blockedBy) {
    if (!window.waOrder || !window.waOrder.form) {
      return;
    }

    // Оформление заблокировано ошибкой, которую увидел только сервер. Сворачивать нечего
    // и незачем: секции ниже упавшего шага ядро в этом запросе не отрисовало, а в самой
    // упавшей группе блок скрыл бы сообщение об ошибке.
    if (blockedBy) {
      if (this.logger) {
        this.logger.info("User attempted to collapse the " + group + " group section while checkout is blocked by " + blockedBy);
      }
      this.showCheckoutBlockedDialog(blockedBy);
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
    this.showNoticeDialog(
      "zen-validation-error",
      this.messages.validation_error_title || "",
      this.messages.validation_error_message || "Validation error"
    );
  }

  /**
   * Сообщает, что оформление заблокировано ошибкой в другом (или в этом же) разделе.
   *
   * @param {string} blockedBy - Имя группы с ошибкой (customer, delivery, payment)
   */
  showCheckoutBlockedDialog(blockedBy) {
    const groupNames = this.messages.group_names || {};
    const template = this.messages.checkout_blocked_message || 'Fix the error in the "%s" section first.';

    this.showNoticeDialog(
      "zen-checkout-blocked",
      this.messages.checkout_blocked_title || "",
      template.replace("%s", groupNames[blockedBy] || blockedBy)
    );
  }

  /**
   * Показывает простое уведомление с кнопкой «ОК».
   *
   * @param {string} dialogId - Идентификатор диалога
   * @param {string} title - Заголовок
   * @param {string} message - Текст (из файлов локализации, не пользовательский ввод)
   */
  showNoticeDialog(dialogId, title, message) {
    if (!this.dialogManager) return;

    const buttonText = this.messages.validation_error_button || "OK";

    // Устанавливаем заголовок
    this.dialogManager.setHeader(dialogId, title);

    // Используем HTML для контента диалога
    const content = `
            <div class="prefill-warning">
                <p class="prefill-warning__text">${message}</p>
                <button class="button prefill-warning__btn js-close-dialog">${buttonText}</button>
            </div>
        `;

    this.dialogManager.showDialog(dialogId, content).then((dialog) => {
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
}

// Экспорт класса в глобальную область для использования в других модулях
window.ZenModeToggle = ZenModeToggle;
