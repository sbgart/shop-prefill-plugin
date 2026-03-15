/**
 * PrefillFrontendController - главный контроллер фронтенда
 * 
 * Ответственность:
 * - Координация работы всех модулей
 * - Инициализация зависимостей с применением Dependency Injection
 * - Предоставление публичного API для обратной совместимости
 * 
 * Архитектура: Фасад (Facade Pattern) + DI (Dependency Injection)
 */
class PrefillFrontendController {
  /**
   * @param {Object} params - Параметры инициализации
   * @param {string} params.pluginID - ID плагина
   * @param {string} params.appUrl - Базовый URL приложения Shop-Script
   * @param {boolean} params.isDebug - Режим отладки
   */
  constructor(params) {
    // Сохраняем параметры для обратной совместимости
    this.pluginID = params.pluginID;
    this.appUrl = params.appUrl;
    this.isDebug = params.isDebug;

    // Создаём зависимости (Dependency Injection)
    this.httpClient = new HttpClient(params.appUrl);
    this.logger = new Logger(params.pluginID, params.isDebug, this.httpClient);
    this.dialogManager = new DialogManager();

    // Создаём менеджеры с инъекцией зависимостей
    this.consentManager = new ConsentManager(
      params.pluginID,
      this.httpClient,
      this.logger,
      this.dialogManager,
      params.messages
    );

    this.paramsChoiceManager = new ParamsChoiceManager(
      this.httpClient,
      this.dialogManager,
      this.logger,
      params.messages,
      params.isAuth,
      params.myDeliveryVariantsEnabled,
      params.zenButtonClasses
    );

    // Создаём ZenModeToggle для управления сворачиванием секций чекаута
    this.zenModeToggle = new ZenModeToggle(this.dialogManager, params.messages, this.logger);

    this.orderFormManager = new OrderFormManager(
      this.paramsChoiceManager,
      this.logger,
      this.zenModeToggle
    );

    // Инициализация всех менеджеров
    this.init();
  }

  /**
   * Инициализирует все менеджеры
   */
  init() {
    this.paramsChoiceManager.init();
    this.orderFormManager.init();
    this.consentManager.init();

    this.logger.log("PrefillFrontendController initialized.");
  }

  // ===================================================================
  // Публичные методы для обратной совместимости
  // Делегируют вызовы соответствующим модулям
  // ===================================================================

  /**
   * Получает или создаёт dialog элемент
   * @deprecated Используйте this.dialogManager.getDialog()
   */
  getDialog(id) {
    return this.dialogManager.getDialog(id);
  }

  /**
   * Отображает диалог с контентом
   * @deprecated Используйте this.dialogManager.showDialog()
   */
  async showDialog(id, content) {
    return await this.dialogManager.showDialog(id, content);
  }

  /**
   * Выполняет POST-запрос для получения HTML контента
   * @deprecated Используйте this.httpClient.fetchView()
   */
  async fetchView(url, data = {}) {
    return await this.httpClient.fetchView(url, data);
  }

  /**
   * Подключает обработчики закрытия диалога
   * @deprecated Используйте this.dialogManager.attachCloseHandler()
   */
  attachDialogCloseHandler(dialog, closeButton) {
    this.dialogManager.attachCloseHandler(dialog, closeButton);
  }

  /**
   * Логирование
   * @deprecated Используйте this.logger.log()
   */
  log(message, type = "log") {
    this.logger.log(message, type);
  }

  /**
   * Отображает диалог выбора параметров
   * @deprecated Используйте this.paramsChoiceManager.displayDialog()
   */
  async displayParamsChoiceDialog() {
    return await this.paramsChoiceManager.displayDialog();
  }

  /**
   * Инициализирует обработчики диалога выбора параметров
   * @deprecated Используйте this.paramsChoiceManager.init()
   */
  addParamsChoiceDialogListeners() {
    // Уже инициализировано в init()
  }

  /**
   * Рендерит ссылку выбора параметров
   * @deprecated Используйте this.paramsChoiceManager.renderLink()
   */
  renderParamsChoiceLink() {
    this.paramsChoiceManager.renderLink();
  }

  /**
   * Инициализирует обработчики событий формы заказа
   * @deprecated Используйте this.orderFormManager.init()
   */
  addOrderFormEventListener() {
    // Уже инициализировано в init()
  }

  /**
   * Инициализирует обработчик checkbox согласия
   * @deprecated Используйте this.consentManager.init()
   */
  addConsentCheckboxListener() {
    // Уже инициализировано в init()
  }
}
