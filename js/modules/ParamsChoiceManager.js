/**
 * ParamsChoiceManager - модуль для управления выбором параметров (адресов)
 * 
 * Ответственность:
 * - Рендеринг ссылки "Мои адреса" в секции доставки
 * - Отображение диалога выбора параметров
 * - Обработка событий клика по ссылкам
 * 
 * Зависимости: HttpClient, DialogManager, Logger
 */
class ParamsChoiceManager {
    /**
     * @param {HttpClient} httpClient - HTTP клиент
     * @param {DialogManager} dialogManager - Менеджер диалогов
     * @param {Logger} logger - Логгер
     * @param {Object} messages - Локализованные сообщения
     */
    constructor(httpClient, dialogManager, logger, messages) {
        this.httpClient = httpClient;
        this.dialogManager = dialogManager;
        this.logger = logger;
        this.messages = messages || {};
    }

    /**
     * Инициализирует обработчики событий
     */
    init() {
        const orderForm = document.getElementById("js-order-form");

        if (orderForm) {
            orderForm.addEventListener("click", async (event) => {
                if (event.target.classList.contains("display-params-choice-dialog")) {
                    event.preventDefault();

                    try {
                        await this.displayDialog();
                    } catch (e) {
                        this.logger.error(e.message);
                    }
                }
            });
        }

        // Делегирование кликов по карточкам вариантов доставки.
        // Карточки рендерятся динамически внутри диалога, поэтому слушаем на document.
        document.addEventListener("click", async (event) => {
            const card = event.target.closest(".prefill-params__card");
            if (!card) return;

            // Игнорируем клик по уже активной карточке
            if (card.classList.contains("is-active")) return;

            const orderId = card.dataset.orderId;
            if (!orderId) return;

            // Визуальная блокировка карточки на время запроса
            card.style.pointerEvents = "none";
            card.style.opacity = "0.6";

            try {
                const response = await this.httpClient.post("prefill/apply-delivery", { order_id: orderId });
                const result = await response.json();

                if (result && result.status === "ok") {
                    const dialog = document.getElementById("prefill-params-choice-dialog");
                    if (dialog) dialog.close();

                    // Используем официальный паттерн ядра Shop-Script для перезагрузки чекаута.
                    // waOrder.form.update() не подходит: он сериализует текущие DOM-инпуты
                    // и перезаписывает сессию, сводя на нет изменения от apply-delivery.
                    // trigger("wa_order_reload_start") блокирует форму визуально, затем
                    // location.reload() перезагружает страницу уже из обновлённой сессии.
                    //$(document).trigger("wa_order_reload_start");
                    //window.location.reload();
                } else {
                    this.logger.error("apply-delivery: unexpected response", result);
                    card.style.pointerEvents = "";
                    card.style.opacity = "";
                }
            } catch (e) {
                this.logger.error("apply-delivery error: " + e.message);
                card.style.pointerEvents = "";
                card.style.opacity = "";
            }
        });
    }

    /**
     * Отображает диалог выбора параметров
     * 
     * @returns {Promise<HTMLDialogElement>}
     */
    async displayDialog() {
        const dialogId = "prefill-params-choice-dialog";
        const content = this.httpClient.fetchView("prefill/params-choice");
        const dialog = await this.dialogManager.showDialog(dialogId, content);

        // Устанавливаем локализованный заголовок
        this.dialogManager.setHeader(dialogId, this.messages.dialog_choose_delivery || "Choose delivery address");

        return dialog;
    }

    /**
     * Рендерит ссылку "Мои адреса" в заголовке секции доставки
     */
    renderLink() {
        const sectionHeader = document.querySelector("#wa-step-region-section .wa-section-header");

        if (!sectionHeader) {
            this.logger.error("Section header not found");
            return;
        }

        const linkId = "params-choice-link";
        let paramsChoiceLink = document.getElementById(linkId);

        if (!paramsChoiceLink) {
            paramsChoiceLink = document.createElement("a");
            paramsChoiceLink.id = linkId;
            paramsChoiceLink.className = "wa-tooltip bottom prefill-params-choice-link";
            paramsChoiceLink.textContent = this.messages.params_choice_link || "Мои варианты";
            paramsChoiceLink.href = "javascript:void(0);";
            paramsChoiceLink.setAttribute("data-title", this.messages.params_choice_link_tooltip || "Мои варианты доставки из прошлых заказов");

            paramsChoiceLink.onclick = async (event) => {
                event.preventDefault();

                try {
                    await this.displayDialog();
                } catch (e) {
                    this.logger.error(e.message);
                }
            };

            sectionHeader.appendChild(paramsChoiceLink);
            this.logger.info("'My Variants' link has been successfully added to the Region section");
        } else {
            this.logger.debug("'My Variants' link already exists in the Region section");
        }
    }
}
