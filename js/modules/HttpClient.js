/**
 * HttpClient - модуль для HTTP-запросов
 * 
 * Ответственность:
 * - Выполнение POST запросов к серверу
 * - Получение HTML контента (views)
 * - Формирование URL для запросов
 * 
 * Зависимости: нет
 */
class HttpClient {
    /**
     * @param {string} baseUrl - Базовый URL приложения Shop-Script
     */
    constructor(baseUrl) {
        this.baseUrl = baseUrl;
    }

    /**
     * Выполняет POST-запрос для получения HTML контента
     *
     * @param {string} url - URL относительно baseUrl
     * @param {Object|Array} data - Данные для отправки: {key: value} или [[key, value], ...]
     *        (второй вариант — для повторяющихся имён вроде "shipping[variant_id]")
     * @returns {Promise<string>} HTML контент
     * @throws {Error} Если запрос завершился с ошибкой
     */
    async fetchView(url, data = {}) {
        const formData = new URLSearchParams(data);

        const response = await fetch(this.baseUrl + url, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
            },
            body: formData.toString(),
        });

        if (!response.ok) {
            throw new Error("Something went wrong.");
        }

        return await response.text();
    }

    /**
     * Универсальный POST-запрос
     * 
     * @param {string} url - URL относительно baseUrl
     * @param {Object} data - Данные для отправки
     * @returns {Promise<Response>} Fetch Response объект
     */
    async post(url, data = {}) {
        const formData = new FormData();
        for (const [key, value] of Object.entries(data)) {
            formData.append(key, value);
        }

        return fetch(this.baseUrl + url, {
            method: "POST",
            body: formData,
        });
    }
}
