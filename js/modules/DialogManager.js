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
            dialog = this._buildDialog(id);
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
        const titleElem = this.getDialog(id).querySelector(".prefill-dialog__title");
        if (titleElem) {
            titleElem.textContent = titleText || "";
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

        if (typeof dialog.showModal !== "function") {
            throw new Error("Метод showDialog не поддерживается этим браузером.");
        }

        dialog.showModal();
        document.body.classList.add("prefill-dialog-open");

        await this._renderContent(dialog, content);

        return dialog;
    }

    // ==========================================
    // ВНУТРЕННИЕ (ПРИВАТНЫЕ) МЕТОДЫ
    // ==========================================

    /**
     * Создает базовую DOM-структуру диалога
     * 
     * @param {string} id - ID диалога
     * @returns {HTMLDialogElement}
     */
    _buildDialog(id) {
        const dialog = document.createElement("dialog");
        dialog.id = id;
        dialog.className = "prefill-dialog";

        dialog.innerHTML = `
            <div class="prefill-dialog__header">
                <h3 class="prefill-dialog__title"></h3>
                <span class="prefill-dialog__close-button"></span>
            </div>
            <div class="prefill-dialog__content"></div>
        `;

        this._attachEvents(dialog);

        return dialog;
    }

    /**
     * Навешивает все необходимые обработчики событий
     * 
     * @param {HTMLDialogElement} dialog - Dialog элемент
     */
    _attachEvents(dialog) {
        const closeButton = dialog.querySelector(".prefill-dialog__close-button");

        // Закрытие по крестику с анимацией
        closeButton.addEventListener("click", () => this.closeDialog(dialog));

        // Перехватываем нативное закрытие по кнопке Esc
        dialog.addEventListener("cancel", (e) => {
            e.preventDefault(); // отменяем немедленное закрытие
            this.closeDialog(dialog);
        });

        // Снимаем блокировку скролла при любом закрытии
        dialog.addEventListener("close", () => {
            document.body.classList.remove("prefill-dialog-open");
        });
    }

    /**
     * Плавное закрытие диалога с отложенным вызовом close()
     * Публичный метод для использования вне класса
     * @param {HTMLDialogElement} dialog 
     */
    closeDialog(dialog) {
        if (dialog.classList.contains('is-closing') || !dialog.open) {
            return;
        }

        dialog.classList.add("is-closing");

        // Ждем окончания CSS анимации: берем среднее 200мс (0.15s для десктопа, 0.25s для мобилок)
        setTimeout(() => {
            dialog.classList.remove("is-closing");
            dialog.close();
        }, 200);
    }

    /**
     * Обрабатывает и рендерит контент внутри диалога
     * 
     * @param {HTMLDialogElement} dialog - Dialog элемент
     * @param {string|Promise<string>} content - HTML контент или Promise
     */
    async _renderContent(dialog, content) {
        const contentDiv = dialog.querySelector(".prefill-dialog__content");

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
    }
}
