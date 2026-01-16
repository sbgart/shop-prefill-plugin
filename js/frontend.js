class PrefillFrontendController {
    constructor(params) {
        this.pluginID = params.pluginID;
        this.appUrl = params.appUrl;  // Базовый URL приложения Shop-Script
        this.isDebug = params.isDebug;

        this.addParamsChoiceDialogListeners();
        this.addOrderFormEventListener();
        this.addConsentCheckboxListener();

        this.log('PrefillFrontendController initialized.');
    }

    getDialog(id) {
        let dialog = document.getElementById(id);
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.id = id;
            dialog.className = 'prefill-dialog';

            // Добавляем в диалог close button
            const closeButton = document.createElement('span')
            closeButton.className = 'prefill-dialog__close-button';
            dialog.prepend(closeButton);

            this.attachDialogCloseHandler(dialog, closeButton);

            // Добавляем в диалог content блок
            const contentDiv = document.createElement('div');
            contentDiv.className = 'prefill-dialog__content';
            dialog.appendChild(contentDiv);

            document.body.appendChild(dialog);
        }

        return dialog;
    }

    async showDialog(id, content) {

        const dialog = this.getDialog(id);

        const contentDiv = dialog.querySelector('.prefill-dialog__content');

        if (typeof dialog.showModal !== "function") {
            throw new Error("Метод showDialog не поддерживается этим браузером.");
        }
        dialog.showModal();

        //if (content instanceof Promise)  - вот так можно проверить, что content - Promise, но долбанные полифилы!
        if (content && typeof content.then === 'function') {
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

    async fetchView(url, data = {}) {

        const formData = new URLSearchParams();
        for (const [key, value] of Object.entries(data)) {
            formData.append(key, value);
        }

        const response = await fetch(url, {
            method: 'POST', headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            }, body: formData.toString()
        });

        if (!response.ok) {
            throw new Error('Что-то пошло не так.');
        }
        return await response.text();
    }

    attachDialogCloseHandler(dialog, closeButton) {
        dialog.addEventListener('click', event => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
        closeButton.addEventListener('click', () => {
            dialog.close();
        });
    }

    log(message, type = 'log') {
        if (!this.isDebug) {
            return;
        }

        if (typeof console[type] === "function") {
            console[type](`[${this.pluginID}] ${message}`);
        }


        message = `[frontend] ${message}`;

        let formData = new FormData();
        formData.append('message', message);
        formData.append('type', type);
        const logUrl = `${this.appUrl}${this.pluginID}/logs`;
        const response = fetch(logUrl, {
            method: 'POST',
            body: formData
        });
    }

    async displayParamsChoiceDialog() {
        const content = this.fetchView(`${this.appUrl}prefill/params-choice`);
        await this.showDialog('prefill-params-choice-dialog', content)
    }

    addParamsChoiceDialogListeners() {
        const orderForm = document.getElementById('js-order-form');
        if (orderForm) {
            orderForm.addEventListener('click', async (event) => {
                if (event.target.classList.contains('display-params-choice-dialog')) {
                    event.preventDefault();
                    try {
                        await this.displayParamsChoiceDialog();
                    } catch (e) {
                        this.log(e.message, 'error');
                    }
                }
            })
        }

    }

    renderParamsChoiceLink() {
        const sectionHeader = document.querySelector('#wa-step-region-section .wa-section-header');
        if (!sectionHeader) {
            this.log('Section header not found', 'error');
            return;
        }

        const linkId = 'params-choice-link';
        let paramsChoiceLink = document.getElementById(linkId);

        if (!paramsChoiceLink) {
            paramsChoiceLink = document.createElement('a');
            paramsChoiceLink.id = linkId;
            paramsChoiceLink.className = "wa-tooltip bottom";
            paramsChoiceLink.textContent = 'Мои адреса';
            paramsChoiceLink.href = 'javascript:void(0);'; // Заменяем '#' на 'javascript:void(0);' чтобы предотвратить перезагрузку страницы
            paramsChoiceLink.setAttribute('data-title', 'Мои адреса на основе прошлых заказов');

            paramsChoiceLink.onclick = async (event) => {
                event.preventDefault(); // Предотвратим действие по умолчанию для ссылки
                try {
                    await this.displayParamsChoiceDialog();
                } catch (e) {
                    this.log(e.message, 'error');
                }
            };

            sectionHeader.appendChild(paramsChoiceLink);
            this.log('Link has been successfully added', 'info');
        } else {
            this.log('Link already exists', 'info');
        }
    }

    addOrderFormEventListener() {
        const that = this;
        $(document).on('wa_order_form_ready', function (e) {
            that.renderParamsChoiceLink();
            that.log('Order form ready, try render link');
        });

        $(document).on('wa_order_form_region_changed', function () {
            that.renderParamsChoiceLink();
            that.log('Order form region changed, try render link');
        });

        $(document).on('wa_order_form_details_changed', function () {
            that.renderParamsChoiceLink();
            that.log('Order form region changed, try render link');
        });
    }

    /**
     * Обработчик галочки согласия на сохранение данных
     */
    addConsentCheckboxListener() {
        const that = this;
        $(document).on('change', '.js-prefill-consent-checkbox', function () {
            const isChecked = $(this).is(':checked');
            const action = isChecked ? 'grant' : 'revoke';

            // Используем полный путь через приложение Shop-Script
            $.post(that.appUrl + that.pluginID + '/consent', { action: action })
                .done(function (response) {
                    that.log('Consent ' + action + ': ' + (response.data?.message || 'ok'));
                })
                .fail(function () {
                    that.log('Failed to update consent', 'error');
                });
        });
    }
}
