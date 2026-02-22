/**
 * ConsentManager - модуль для управления согласиями пользователя
 * 
 * Ответственность:
 * - Инициализация обработчиков checkbox согласия
 * - Обработка изменений состояния согласия
 * - Отправка запросов на сервер для сохранения/удаления согласия
 * 
 * Зависимости: HttpClient, Logger
 */
class ConsentManager {
    /**
     * @param {string} pluginID - ID плагина для формирования URL
     * @param {HttpClient} httpClient - HTTP клиент
     * @param {Logger} logger - Логгер
     */
    constructor(pluginID, httpClient, logger) {
        this.pluginID = pluginID;
        this.httpClient = httpClient;
        this.logger = logger;
    }

    /**
     * Инициализирует обработчики событий для checkbox согласия
     */
    init() {
        const that = this;

        $(document).on("change", ".js-prefill-consent-checkbox", function () {
            const isChecked = $(this).is(":checked");
            const action = isChecked ? "grant" : "revoke";

            $.post(that.httpClient.baseUrl + that.pluginID + "/consent", { action: action })
                .done(function (response) {
                    that.logger.info("Consent " + action + " action completed: " + (response.data?.message || "ok"));
                })
                .fail(function () {
                    that.logger.error("Failed to update user consent state for action: " + action);
                });
        });
    }
}
