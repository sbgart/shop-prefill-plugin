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

    // data-blocked-by ставит сервер на каждом рендере и только тем группам, чьих секций
    // в ответе нет вовсе: конвейер остановила ошибка выше. Переключать такую группу нечем —
    // ядро её не отрисовало, — поэтому клик уходит не в отказ, а в блок, где работа.
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
    // их вернёт эхо-кэш, как только причина выше будет устранена. Ведём его туда,
    // а куку не трогаем: иначе группа развернулась бы в пустоту на следующем рендере.
    if (blockedBy) {
      if (this.logger) {
        this.logger.info("User attempted to expand the " + group + " group section while checkout is blocked by " + blockedBy);
      }
      this.guideToBlockingGroup(blockedBy);
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

    // Сворачивать нечего: секций этой группы ядро в этом запросе не отрисовало. На экране
    // такая кнопка обычно и не видна (секция осталась скрытой), но ветка симметрична
    // разворачиванию — ведём в блок, где работа, а не отказываем.
    if (blockedBy) {
      if (this.logger) {
        this.logger.info("User attempted to collapse the " + group + " group section while checkout is blocked by " + blockedBy);
      }
      this.guideToBlockingGroup(blockedBy);
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

      // Сворачивание могло не состояться: минимума данных нет, и сервер оставит группу
      // развёрнутой (Z2). Узнаём это по перерисованной разметке, а не по флагу до клика —
      // покупатель мог заполнить поле уже после последнего рендера, и тогда сворачивание
      // законно, а предупреждение было бы враньём.
      var self = this;
      var updated = form.update();
      if (updated && typeof updated.then === "function") {
        updated.then(function () {
          self.warnIfNothingToSummarize(group);
        });
      }
    } else {
      if (this.logger) {
        this.logger.info("User attempted to collapse the " + group + " group section, but validation failed");
      }
      // Валидация не прошла: ядро уже нарисовало подсказки (render_errors) — подводим
      // покупателя к ним, иначе подсказка под полем в начале блока остаётся за экраном,
      // а кнопка «Свернуть» находится в его конце.
      this.scrollToReason(sections);
      this.showValidationErrorDialog();
    }
  }

  /**
   * Диалог для обеих веток: клик по «Свернуть» при незаполненной группе и клик по
   * заблокированной кнопке (guideToBlockingGroup()).
   *
   * Текст общий сознательно: он верен, каким бы ни был повод — пустое поле, неверное
   * значение, невыбранный способ, серверная проверка. Конкретику несёт подсвеченное поле.
   */
  showValidationErrorDialog() {
    this.showNoticeDialog(
      "zen-validation-error",
      this.messages.validation_error_title || "",
      this.messages.validation_error_message || "Validation error"
    );
  }

  /**
   * После пересчёта проверяет, свернулась ли группа, и объясняет, если нет.
   *
   * Признак `data-nothing-to-summarize` ставит сервер тем же решением, которым отказался
   * сворачивать (Z2). Читаем его уже после обновления формы: там сервер видел свежие
   * данные покупателя, а не те, что были на предыдущем рендере.
   *
   * @param {string} group - Имя группы
   */
  warnIfNothingToSummarize(group) {
    var selector = '.js-prefill-zen-toggle[data-group="' + group + '"][data-nothing-to-summarize]';
    if (!document.querySelector(selector)) {
      return;
    }

    if (this.logger) {
      this.logger.info("Collapse of the " + group + " group section had no effect: nothing to summarize yet");
    }
    this.showNothingToSummarizeDialog();
  }

  /**
   * Сообщает, что группу нечего сворачивать: минимума данных нет.
   * Свои строки, а не validation_error: ошибки в форме нет и подсказок под полями тоже —
   * покупатель просто ещё ничего не заполнил, и «Сейчас проверю» на кнопке звало бы
   * проверять несуществующие ошибки.
   */
  showNothingToSummarizeDialog() {
    this.showNoticeDialog(
      "zen-nothing-to-summarize",
      this.messages.nothing_to_summarize_title || "",
      this.messages.nothing_to_summarize_message || "A block can be collapsed once it has data.",
      this.messages.nothing_to_summarize_button
    );
  }

  /**
   * Уводит покупателя туда, где работа: просит ядро подсветить проблему в блокирующей
   * группе, прокручивает к ней и отвечает на клик тем же диалогом, что и валидация.
   *
   * Почему не отказ диалогом «исправьте ошибку». Ошибки на экране не видно: ядро рисует
   * свой текст («Выберите вариант доставки», «Выберите способ оплаты») только когда кто-то
   * зовёт валидацию с render_errors, а в заблокированном состоянии её не звал никто.
   * Конкретику покупателю даёт само подсвеченное поле, слова в нём — ядра.
   *
   * Почему диалог без причин. Пробовали перечислять тексты ядра в диалоге, отказались:
   * ошибка поля приходит голой («Обязательное поле»), и её подпись пришлось бы доставать
   * из вёрстки темы; клиентский и серверный тексты одной ошибки различаются («Неправильное
   * значение» и «Почта введена неправильно»); ошибки плагинов доставки и оплаты приходят
   * их собственными строками, вплоть до английских. Общий текст верен всегда.
   *
   * @param {string} blockingGroup - Группа, из-за которой ядро остановило конвейер
   */
  guideToBlockingGroup(blockingGroup) {
    var form = window.waOrder && window.waOrder.form;
    if (!form || !form.sections) {
      return;
    }

    var sections = this.getSectionsForGroup(blockingGroup);

    // Только ради отрисовки: результат не нужен, значения покупателя не трогаем (clean: false)
    this.validateSections(form, sections);

    this.scrollToReason(sections);
    this.showValidationErrorDialog();
  }

  /**
   * Прокручивает к причине: к сообщению ядра, если оно есть, иначе к первой видимой
   * секции группы — там причина показана самим сервером (забаненный контакт, чужой email).
   *
   * @param {Array<string>} sections - Секции блокирующей группы, в порядке шагов
   */
  scrollToReason(sections) {
    var firstVisibleSection = null;
    var reason = null;

    sections.forEach(function (sectionName) {
      var wrapper = document.getElementById("wa-step-" + sectionName + "-section");
      if (!wrapper || wrapper.offsetParent === null) {
        return;
      }

      if (!firstVisibleSection) {
        firstVisibleSection = wrapper;
      }

      if (!reason) {
        reason = Array.prototype.find.call(wrapper.querySelectorAll(".wa-error-text"), function (el) {
          return el.offsetParent !== null;
        });
      }
    });

    var target = reason || firstVisibleSection;
    if (target) {
      // behavior: "instant" обязателен: тема ставит на html `scroll-behavior: smooth`, и без
      // явного значения прокрутка идёт плавно, а диалог, открывающийся следом, обрывает её
      // на первом же кадре — покупатель остаётся там, где стоял (проверено 19.09.2026).
      target.scrollIntoView({ block: "center", behavior: "instant" });
    }
  }

  /**
   * Показывает простое уведомление с кнопкой «ОК».
   *
   * @param {string} dialogId - Идентификатор диалога
   * @param {string} title - Заголовок
   * @param {string} message - Текст (из файлов локализации, не пользовательский ввод)
   * @param {string} [ownButtonText] - Своя подпись кнопки; по умолчанию — общая
   */
  showNoticeDialog(dialogId, title, message, ownButtonText) {
    if (!this.dialogManager) return;

    const buttonText = ownButtonText || this.messages.validation_error_button || "OK";

    // Устанавливаем заголовок
    this.dialogManager.setHeader(dialogId, title);

    // Общий шаблон блока-предупреждения — templates/checkout/DialogTemplates.html
    const content = this.dialogManager.buildWarningContent(message, buttonText);
    content.querySelector(".prefill-warning__btn").classList.add("js-close-dialog");

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
