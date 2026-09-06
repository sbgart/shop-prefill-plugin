/**
 * Logger - модуль для логирования
 * 
 * Ответственность:
 * - Логирование в консоль браузера
 * - Отправка логов на сервер (в режиме отладки)
 * - Предоставление удобных методов log/info/warn/error
 * 
 * Зависимости: HttpClient
 */
class Logger {
    /**
     * @param {string} pluginID - ID плагина для префикса
     * @param {boolean} isDebug - Режим отладки
     * @param {HttpClient} httpClient - HTTP клиент для отправки логов
     * @param {boolean} canSendServerLogs - Разрешена ли отправка в серверный лог
     */
    constructor(pluginID, isDebug, httpClient, canSendServerLogs = false) {
        this.pluginID = pluginID;
        this.isDebug = isDebug;
        this.httpClient = httpClient;
        this.canSendServerLogs = canSendServerLogs;
    }

    /**
     * Универсальное логирование
     * 
     * @param {string} message - Сообщение для логирования
     * @param {string} type - Тип лога: 'log', 'info', 'warn', 'error'
     */
    log(message, type = "log") {
        if (!this.isDebug) {
            return;
        }

        // Логирование в консоль браузера
        if (typeof console[type] === "function") {
            console[type](`[${this.pluginID}] ${message}`);
        }

        // Серверный endpoint административный. Для гостей и покупателей оставляем
        // сообщение в консоли, не создавая заведомо отклоняемый POST на каждый лог.
        if (this.canSendServerLogs) {
            this.httpClient.post(`${this.pluginID}/logs`, {
                message: message,
                type: type,
            }).catch(() => {
                // Логирование не должно создавать unhandled rejection и ломать checkout.
            });
        }
    }

    /**
     * Информационное сообщение
     * @param {string} message
     */
    info(message) {
        this.log(message, "info");
    }

    /**
     * Предупреждение
     * @param {string} message
     */
    warn(message) {
        this.log(message, "warn");
    }

    /**
     * Отладка
     * @param {string} message
     */
    debug(message) {
        this.log(message, "debug");
    }

    /**
     * Ошибка
     * @param {string} message
     */
    error(message) {
        this.log(message, "error");
    }
}
