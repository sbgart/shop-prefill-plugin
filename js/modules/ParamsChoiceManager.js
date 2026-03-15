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
     * @param {boolean} isAuth - Авторизован ли пользователь
     * @param {boolean} isFeatureEnabled - Включена ли опция глобально
     * @param {string} buttonClasses - Дополнительные CSS-классы для кнопки "Мои варианты"
     */
    constructor(httpClient, dialogManager, logger, messages, isAuth = false, isFeatureEnabled = true, buttonClasses = '') {
        this.httpClient = httpClient;
        this.dialogManager = dialogManager;
        this.logger = logger;
        this.messages = messages || {};
        this.isAuth = isAuth;
        this.isFeatureEnabled = isFeatureEnabled;
        this.buttonClasses = buttonClasses;
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

        // Слушаем событие от PHP: shipping[type_id] не заполнился после выбора варианта
        $(document).on('prefill_delivery_unavailable', () => {
            this.showDeliveryUnavailableDialog();
        });

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
                    if (dialog) this.dialogManager.closeDialog(dialog);

                    // Ставим одноразовую куку-флаг: после перезагрузки PHP проверит
                    // shipping[type_id] и при необходимости вызовет предупреждение.
                    document.cookie = 'prefill_user_selected=1; path=/; SameSite=Lax';

                    // Используем официальный паттерн ядра Shop-Script для перезагрузки чекаута.
                    // waOrder.form.update() не подходит: он сериализует текущие DOM-инпуты
                    // и перезаписывает сессию, сводя на нет изменения от apply-delivery.
                    // trigger("wa_order_reload_start") блокирует форму визуально, затем
                    // location.reload() перезагружает страницу уже из обновлённой сессии.
                    $(document).trigger("wa_order_reload_start");
                    window.location.reload();
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
     * Показывает диалог-предупреждение о недоступности выбранного способа доставки.
     * Содержит кнопку «Выбрать другой способ», которая открывает основной dialog выбора.
     */
    async showDeliveryUnavailableDialog() {
        // Гасим куку сразу — PHP не делает этого в failure-ветке, поэтому скрипт есть
        // во всех AJAX-ответах checkout до финального рендера. Гасим при первом срабатывании.
        document.cookie = 'prefill_user_selected=; max-age=0; path=/';

        // Защита от повторного показа: если диалог уже открыт — выходим.
        // Edge-case: несколько параллельных AJAX-ответов с <script> до гашения куки.
        const existing = document.getElementById('prefill-delivery-unavailable-dialog');
        if (existing?.open) return;

        const dialogId = 'prefill-delivery-unavailable-dialog';
        const title = this.messages.delivery_unavailable_title || 'Delivery unavailable';
        const text = this.messages.delivery_unavailable_text || 'The selected delivery method is not available.';
        const btnLabel = this.messages.delivery_unavailable_button || 'Choose another method';

        const html = `
            <div class="prefill-warning">
                <p class="prefill-warning__text">${text}</p>
                <button class="button prefill-warning__btn" id="prefill-choose-another-delivery">${btnLabel}</button>
            </div>`;


        const dialog = await this.dialogManager.showDialog(dialogId, Promise.resolve(html));
        this.dialogManager.setHeader(dialogId, title);

        // Кнопка «Выбрать другой способ» → закрываем и открываем dialog вариантов
        dialog.querySelector('#prefill-choose-another-delivery')
            ?.addEventListener('click', async () => {
                this.dialogManager.closeDialog(dialog);
                await this.displayDialog();
            });
    }

    /**
     * Рендерит ссылку "Мои адреса" в заголовке секции доставки
     */
    renderLink() {
        if (!this.isAuth || !this.isFeatureEnabled) {
            this.logger.debug("renderLink aborted: user is disabled or feature is turned off");
            return;
        }

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
            
            // Формируем список классов
            const classes = [
                "wa-tooltip bottom prefill-params-choice-link",
                this.buttonClasses
            ].filter(Boolean).join(" ");
            
            paramsChoiceLink.className = classes;
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
