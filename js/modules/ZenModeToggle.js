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

    // Пишем просьбу, а не состояние: сервер отличит «попросили только что» от «было развёрнуто»
    // и откажет, если разворачивать нечего — шаг группы в ответе не считался. Иначе блок
    // исчезал с экрана целиком (docs/bugs/done/zen-block-vanishes-on-stale-blocked-flag.md).
    // Значение знает сервер: shopPrefillPluginZenLatch::EXPAND_REQUEST.
    // max-age короткий: просьба имеет смысл только для ближайшего пересчёта. Если ядро
    // в этом ответе не перерисует секцию-носителя, сервер её не погасит — и она должна
    // истечь сама, а не сработать на действии, которого покупатель уже не ждёт.
    document.cookie = cookieName + "=expand-request; path=/; SameSite=Lax; max-age=60";

    // Обновляем форму заказа
    if (window.waOrder && window.waOrder.form) {
      if (this.logger) {
        this.logger.info("User asked to expand the " + group + " group section");
      }

      var self = this;
      var updated = window.waOrder.form.update();
      if (updated && typeof updated.then === "function") {
        updated.then(function () {
          self.reactToServerDecision(group, "expand");
        });
      }
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
    var validation = this.validateSections(form, sections);

    if (!validation.hasErrors) {
      var cookieName = "prefill_zen_" + group;

      // Валидация успешна → удаляем cookie и обновляем форму (бэкенд при ошибках снова проставит expanded)
      document.cookie = cookieName + "=; path=/; SameSite=Lax; max-age=0";

      if (this.logger) {
        this.logger.info("User asked to collapse the " + group + " group section");
      }

      // Сворачивание могло не состояться: данных нет (Z2) либо в группе ошибка, которой
      // клиент не видит (Z1). Узнаём это по перерисованной разметке, а не по флагу до клика —
      // покупатель мог заполнить поле уже после последнего рендера, и тогда сворачивание
      // законно, а предупреждение было бы враньём.
      var self = this;
      var updated = form.update();
      if (updated && typeof updated.then === "function") {
        updated.then(function () {
          self.reactToServerDecision(group, "collapse");
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
      this.showValidationErrorDialog(this.resolveReasonMessage(validation.reasons));
    }
  }

  /**
   * Диалог для обеих веток: клик по «Свернуть» при незаполненной группе и клик по
   * заблокированной кнопке (guideToBlockingGroup()).
   *
   * @param {string} [message] - Точный текст повода; без него — общий
   */
  showValidationErrorDialog(message) {
    this.showNoticeDialog(
      "zen-validation-error",
      this.messages.validation_error_title || "",
      message || this.messages.validation_error_message || "Validation error"
    );
  }

  /**
   * Выбирает текст диалога по опознавателям поводов.
   *
   * Точный текст даём только когда повод ровно один и он нам известен. Несколько поводов —
   * перечислять их в диалоге мы отказались ещё в сентябре; неизвестный повод — тем более:
   * у ошибок полей опознавателя нет, их название пришлось бы доставать из вёрстки темы, а
   * серверные причины (почта, бан, чужой контакт) клиенту вообще не видны. Во всех этих
   * случаях общий текст верен, а точный соврал бы.
   *
   * @param {Array<string>} reasons - Опознаватели из validateSections()
   * @returns {string|undefined} Точный текст либо undefined, если его нет
   */
  resolveReasonMessage(reasons) {
    var known = this.messages.validation_reasons || {};

    if (!reasons || reasons.length !== 1) {
      return undefined;
    }

    return known[reasons[0]];
  }

  /**
   * Отвечает на клик тем, что сервер действительно сделал.
   *
   * Исход знает только сервер: клиентская проверка не воспроизводит ни серверные ошибки
   * (почта, бан, чужой контакт), ни отказ развернуть группу, шаг которой в этом ответе не
   * считался. Поэтому читаем свежую разметку после пересчёта, а не признаки, снятые до
   * клика: к моменту клика они могли устареть — именно так блок оплаты исчезал с экрана
   * (docs/bugs/done/zen-block-vanishes-on-stale-blocked-flag.md).
   *
   * Признаки ставит сервер и пересчитывает на каждом рендере: `data-blocked-by` — шаг группы
   * не считался, `data-nothing-to-summarize` — сводить нечего (Z2), `data-has-errors` —
   * в группе ошибка (Z1).
   *
   * @param {string} group - Имя группы
   * @param {string} intent - Чего просил покупатель: "expand" или "collapse"
   */
  reactToServerDecision(group, intent) {
    var buttons = document.querySelectorAll('.js-prefill-zen-toggle[data-group="' + group + '"]');
    if (!buttons.length) {
      return;
    }

    var verdict = { collapsed: false, blockedBy: null, nothingToSummarize: false, hasErrors: false };

    Array.prototype.forEach.call(buttons, function (btn) {
      // Свёрнутая группа несёт кнопку «Изменить», развёрнутая — «Свернуть» (Z3)
      if (btn.dataset.action === "expand") {
        verdict.collapsed = true;
      }
      if (btn.dataset.blockedBy) {
        verdict.blockedBy = btn.dataset.blockedBy;
      }
      if (btn.dataset.nothingToSummarize) {
        verdict.nothingToSummarize = true;
      }
      if (btn.dataset.hasErrors) {
        verdict.hasErrors = true;
      }
    });

    if (intent === "expand") {
      if (!verdict.collapsed) {
        if (this.logger) {
          this.logger.info("The " + group + " group section has been expanded");
        }
        return;
      }

      // Сервер отказал: разворачивать нечего, его шаг в этом ответе не считался. Блок при
      // этом остался на месте — ведём покупателя туда, где работа. Куку не трогаем: сервер
      // уже погасил просьбу, а вслепую стирать её опасно — так можно отменить и удачный
      // разворот, если секция в этом ответе не перерисовалась.
      if (this.logger) {
        this.logger.info("Server refused to expand the " + group + " group section: blocked by " + (verdict.blockedBy || "unknown"));
      }
      if (verdict.blockedBy) {
        this.guideToBlockingGroup(verdict.blockedBy);
      }
      return;
    }

    if (verdict.collapsed) {
      if (this.logger) {
        this.logger.info("The " + group + " group section has been collapsed");
      }
      return;
    }

    if (verdict.nothingToSummarize) {
      if (this.logger) {
        this.logger.info("Server kept the " + group + " group section expanded: nothing to summarize yet");
      }
      this.showNothingToSummarizeDialog();
      return;
    }

    if (verdict.hasErrors) {
      if (this.logger) {
        this.logger.info("Server kept the " + group + " group section expanded: the group has errors");
      }
      // Подсказки уже нарисовал сервер в перерисованной секции — подводим к ним
      this.scrollToReason(this.getSectionsForGroup(group));
      this.showValidationErrorDialog();
    }
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

    // Отрисовка подсказок плюс опознаватели поводов: в блокирующей группе чаще всего просто
    // не выбран вариант доставки — об этом и скажем, вместо общих слов (clean: false)
    var validation = this.validateSections(form, sections);

    this.scrollToReason(sections);
    this.showValidationErrorDialog(this.resolveReasonMessage(validation.reasons));
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
   * Возвращает не только факт ошибок, но и их опознаватели: по ним диалог говорит точнее.
   * Опознаватель есть только у «не выбрано» (`method_required`, `variant_required`,
   * `type_required` в js/frontend/order/form.js) — у ошибок полей его нет, и за них отвечает
   * пустая строка: один такой повод уже делает набор неизвестным, и текст остаётся общим.
   *
   * @param {Object} form - Объект формы waOrder
   * @param {Array<string>} sections - Массив имён секций для валидации
   * @returns {{hasErrors: boolean, reasons: Array<string>}}
   */
  validateSections(form, sections) {
    var result = { hasErrors: false, reasons: [] };

    sections.forEach(function (sectionName) {
      var section = form.sections[sectionName];

      if (section && typeof section.getData === "function") {
        var sectionData = section.getData({
          clean: false,
          render_errors: true,
        });

        if (sectionData.errors && sectionData.errors.length > 0) {
          result.hasErrors = true;
          sectionData.errors.forEach(function (error) {
            result.reasons.push(error && error.id ? error.id : "");
          });
        }
      }
    });

    return result;
  }
}

// Экспорт класса в глобальную область для использования в других модулях
window.ZenModeToggle = ZenModeToggle;
