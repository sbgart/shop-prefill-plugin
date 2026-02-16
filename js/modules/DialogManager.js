/**
 * DialogManager - модуль для управления диалоговыми окнами
 * 
 * Ответственность:
 * - Создание и получение dialog элементов
 * - Отображение диалогов с поддержкой Promise для контента
 * - Управление обработчиками закрытия
 * 
 * Зависимости: нет (работает только с DOM)
 */
class DialogManager {
    /**
     * Получает или создаёт dialog элемент с указанным ID
     * 
     * @param {string} id - ID диалога
     * @returns {HTMLDialogElement} Dialog элемент
     */
    getDialog(id) {
        let dialog = document.getElementById(id);

        if (!dialog) {
            dialog = document.createElement("dialog");
            dialog.id = id;
            dialog.className = "prefill-dialog";

            // Добавляем хедер
            const headerDiv = document.createElement("div");
            headerDiv.className = "prefill-dialog__header";
            dialog.appendChild(headerDiv);

            // Добавляем заголовок в хедер
            const titleElem = document.createElement("h3");
            titleElem.className = "prefill-dialog__title";
            headerDiv.appendChild(titleElem);

            // Добавляем кнопку закрытия в хедер
            const closeButton = document.createElement("span");
            closeButton.className = "prefill-dialog__close-button";
            headerDiv.appendChild(closeButton);

            this.attachCloseHandler(dialog, closeButton);

            // Добавляем блок для контента
            const contentDiv = document.createElement("div");
            contentDiv.className = "prefill-dialog__content";
            dialog.appendChild(contentDiv);

            document.body.appendChild(dialog);
        }

        return dialog;
    }

    /**
     * Устанавливает заголовок диалога
     * 
     * @param {string} id - ID диалога
     * @param {string} titleText - Текст заголовка
     */
    setHeader(id, titleText) {
        const dialog = this.getDialog(id);
        const titleElem = dialog.querySelector(".prefill-dialog__title");
        if (titleElem) {
            titleElem.innerText = titleText || "";
        }
    }

    /**
     * Отображает диалог с заданным контентом
     * 
     * @param {string} id - ID диалога
     * @param {string|Promise<string>} content - HTML контент или Promise, возвращающий HTML
     * @returns {Promise<HTMLDialogElement>} Dialog элемент после загрузки контента
     * @throws {Error} Если браузер не поддерживает showModal
     */
    async showDialog(id, content) {
        const dialog = this.getDialog(id);
        const contentDiv = dialog.querySelector(".prefill-dialog__content");

        if (typeof dialog.showModal !== "function") {
            throw new Error("Метод showDialog не поддерживается этим браузером.");
        }

        dialog.showModal();

        // Проверяем, является ли content Promise
        if (content && typeof content.then === "function") {
            contentDiv.innerHTML = '<div class="prefill-dialog__loading">Готовим контент...</div>';

            try {
                content = await content;
            } catch (error) {
                content = '<div class="prefill-dialog__error">Ошибка получения контента, попробуйте позже.</div>';
            }
        }

        contentDiv.innerHTML = content;

        return dialog;
    }

    /**
     * Подключает обработчики закрытия диалога
     * 
     * @param {HTMLDialogElement} dialog - Dialog элемент
     * @param {HTMLElement} closeButton - Кнопка закрытия
     */
    attachCloseHandler(dialog, closeButton) {
        // Закрытие при клике на кнопку закрытия
        closeButton.addEventListener("click", () => {
            dialog.close();
        });
    }
}
