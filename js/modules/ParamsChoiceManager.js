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
     */
    constructor(httpClient, dialogManager, logger) {
        this.httpClient = httpClient;
        this.dialogManager = dialogManager;
        this.logger = logger;
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
        const content = this.httpClient.fetchView("prefill/params-choice");
        return await this.dialogManager.showDialog("prefill-params-choice-dialog", content);
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
            paramsChoiceLink.className = "wa-tooltip bottom";
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
            this.logger.info("Link has been successfully added");
        } else {
            this.logger.info("Link already exists");
        }
    }
}
