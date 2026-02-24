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
            paramsChoiceLink.textContent = "Мои адреса";
            paramsChoiceLink.href = "javascript:void(0);";
            paramsChoiceLink.setAttribute("data-title", "Мои адреса на основе прошлых заказов");

            paramsChoiceLink.onclick = async (event) => {
                event.preventDefault();

                try {
                    await this.displayDialog();
                } catch (e) {
                    this.logger.error(e.message);
                }
            };

            sectionHeader.appendChild(paramsChoiceLink);
            this.logger.info("'My Addresses' link has been successfully added to the Region section");
        } else {
            this.logger.debug("'My Addresses' link already exists in the Region section");
        }
    }
}
