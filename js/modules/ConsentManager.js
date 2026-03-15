/**
 * ConsentManager - модуль для управления согласиями пользователя
 *
 * Ответственность:
 * - Инициализация обработчиков checkbox согласия
 * - Обработка изменений состояния согласия
 * - Отправка запросов на сервер для сохранения/удаления согласия
 * - Диалог подтверждения при снятии галочки: очистить ли данные формы
 *
 * Зависимости: HttpClient, Logger, DialogManager, messages
 */
class ConsentManager {
    /** ID диалога подтверждения очистки формы */
    static get CONSENT_REVOKE_DIALOG_ID() {
        return "prefill-consent-revoke-confirm";
    }

    /**
     * @param {string} pluginID - ID плагина для формирования URL
     * @param {HttpClient} httpClient - HTTP клиент
     * @param {Logger} logger - Логгер
     * @param {DialogManager} dialogManager - Менеджер диалогов
     * @param {Object} messages - Объект с ключами consent_revoke_title, consent_revoke_text, consent_revoke_confirm, consent_revoke_cancel
     */
    constructor(pluginID, httpClient, logger, dialogManager, messages) {
        this.pluginID = pluginID;
        this.httpClient = httpClient;
        this.logger = logger;
        this.dialogManager = dialogManager;
        this.messages = messages || {};
    }

    /**
     * Инициализирует обработчики событий для checkbox согласия
     */
    init() {
        const that = this;

        $(document).on("change", ".js-prefill-consent-checkbox", function () {
            const checkbox = this;
            const isChecked = $(checkbox).is(":checked");

            if (isChecked) {
                that._sendConsentAction("grant");
                return;
            }

            // Снятие галочки: сначала revoke, затем диалог «очистить данные формы?»
            that._sendConsentAction("revoke").then(function () {
                that.dialogManager
                    .showConfirm(
                        ConsentManager.CONSENT_REVOKE_DIALOG_ID,
                        that.messages.consent_revoke_title || "",
                        that.messages.consent_revoke_text || "",
                        that.messages.consent_revoke_confirm || "",
                        that.messages.consent_revoke_cancel || ""
                    )
                    .then(function (clearForm) {
                        if (clearForm) {
                            that._sendConsentAction("clear_form").then(function () {
                                location.reload();
                            });
                        }
                    });
            });
        });
    }

    /**
     * Отправляет действие согласия на сервер
     * @param {string} action - grant | revoke | clear_form
     * @returns {jqXHR}
     */
    _sendConsentAction(action) {
        const that = this;
        return $.post(this.httpClient.baseUrl + this.pluginID + "/consent", { action: action })
            .done(function (response) {
                that.logger.info("Consent " + action + " action completed: " + (response.data?.message || "ok"));
            })
            .fail(function () {
                that.logger.error("Failed to update user consent state for action: " + action);
            });
    }
}
