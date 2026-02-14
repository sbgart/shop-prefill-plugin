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
     */
    constructor(pluginID, isDebug, httpClient) {
        this.pluginID = pluginID;
        this.isDebug = isDebug;
        this.httpClient = httpClient;
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

        // Отправка лога на сервер (асинхронно, не ждём ответа)
        const serverMessage = `[frontend] ${message}`;
        this.httpClient.post(`${this.pluginID}/logs`, {
            message: serverMessage,
            type: type,
        });
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
     * Ошибка
     * @param {string} message
     */
    error(message) {
        this.log(message, "error");
    }
}
