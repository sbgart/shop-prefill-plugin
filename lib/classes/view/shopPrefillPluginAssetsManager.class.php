<?php

/**
 * Менеджер управления CSS/JS ресурсами фронтенда
 * Отвечает за генерацию и подключение статических файлов
 */
class shopPrefillPluginAssetsManager
{
    private string $plugin_id;
    private bool $assets_initialized = false;
    private ?waResponse $response = null;

    public function __construct(string $plugin_id)
    {
        $this->plugin_id = $plugin_id;
    }

    /**
     * Инициализирует CSS и JS ресурсы фронтенда
     *
     * @param bool $is_debug Режим отладки
     * @param array $css_variables CSS переменные для генерации
     * @param array $js_params Параметры для инициализации JS
     * @param callable $add_css Callback для добавления CSS (addCss из плагина)
     * @param callable $add_js Callback для добавления JS (addJs из плагина)
     * @param string $storefront_css_url Публичный URL per-storefront CSS (пустой = грузить штатный frontend.css)
     * @throws waException
     */
    public function init(
        bool $is_debug,
        array $css_variables,
        array $js_params,
        callable $add_css,
        callable $add_js,
        string $storefront_css_url = ''
    ): void {
        if ($this->assets_initialized) {
            return;
        }

        // Per-storefront CSS заменяет штатный frontend.css; если файла нет — грузим штатный
        if ($storefront_css_url !== '') {
            $this->getResponse()->addCss($storefront_css_url);
        } else {
            $add_css('css/frontend.' . (!$is_debug ? 'min.' : '') . 'css');
        }

        if ($is_debug) {
            // Debug: модули отдельными файлами (по зависимостям) + неминифицированный контроллер.
            // Так удобнее дебажить в devtools и не нужно пересобирать бандл на каждое изменение.
            $add_js('js/modules/HttpClient.js');
            $add_js('js/modules/DialogManager.js');
            $add_js('js/modules/Logger.js'); // зависит от HttpClient
            $add_js('js/modules/ConsentManager.js'); // зависит от HttpClient, Logger
            $add_js('js/modules/ParamsChoiceManager.js'); // зависит от HttpClient, DialogManager, Logger
            $add_js('js/modules/OrderFormManager.js'); // зависит от ParamsChoiceManager, Logger
            $add_js('js/modules/ZenModeToggle.js'); // управление Zen Mode (без зависимостей)
            $add_js('js/prefill.frontend.js');
        } else {
            // Prod: один самодостаточный минифицированный бандл (модули уже внутри).
            // Собирается скиллом build-plugin-frontend из js/bundle.config.json.
            $add_js('js/prefill.frontend.min.js');
        }

        // Генерируем и подключаем CSS переменные
        if (!empty($css_variables)) {
            $css_variables_filename = $this->generateCssVariablesFile($css_variables);
            $this->getResponse()->addCss($this->getPublicDataPath('css') . $css_variables_filename);
        }

        // Генерируем и подключаем JS инициализатор
        $js_initializer_filename = $this->generateJSInitializerFile($js_params);
        $this->getResponse()->addJs($this->getPublicDataPath('js') . $js_initializer_filename);

        $this->assets_initialized = true;
    }

    /**
     * Генерирует файл с CSS переменными
     *
     * @param array $css_variables Массив CSS переменных
     * @return string Имя сгенерированного файла
     * @throws waException
     */
    public function generateCssVariablesFile(array $css_variables): string
    {
        $css_variables_map = $this->createCssVariablesString($css_variables);
        $hash = md5($css_variables_map);
        $css_variables_filename = "variables_{$hash}.css";
        $css_public_dir = wa()->getDataPath("plugins/{$this->plugin_id}/css/", true, 'shop');

        if (!file_exists("{$css_public_dir}{$css_variables_filename}")) {
            file_put_contents("{$css_public_dir}{$css_variables_filename}", $css_variables_map);
        }

        return $css_variables_filename;
    }

    /**
     * Генерирует файл с JS инициализатором
     *
     * @param array $params Параметры для передачи в JavaScript
     * @return string Имя сгенерированного файла
     * @throws waException
     */
    public function generateJSInitializerFile(array $params): string
    {
        $json_params = json_encode(
            $params,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        $inline_script = <<<JS
document.addEventListener('DOMContentLoaded', function() {
    let params = $json_params;
    window.prefill = new PrefillFrontendController(params);
});
JS;
        $hash = md5($inline_script);
        $js_file_name = "{$hash}.js";
        $js_public_dir = wa()->getDataPath("plugins/{$this->plugin_id}/js/", true, 'shop');

        if (!file_exists("{$js_public_dir}{$js_file_name}")) {
            file_put_contents("{$js_public_dir}{$js_file_name}", $inline_script);
        }

        return $js_file_name;
    }

    /**
     * Создает строку с CSS переменными в формате :root { --var: value; }
     * Перенесено из shopPrefillPluginViewProvider
     *
     * @param array $params Массив параметров вида ['var-name' => 'value']
     * @return string CSS код с переменными
     */
    public function createCssVariablesString(array $params): string
    {
        if (empty($params)) {
            return '';
        }

        $css_variables = [];
        foreach ($params as $key => $value) {
            $css_variables[] = "    --{$key}: {$value};";
        }

        return ":root {\n" . implode("\n", $css_variables) . "\n}\n";
    }

    /**
     * Возвращает публичный URL-путь к поддиректории данных плагина (без ведущего /).
     * wa()->getDataUrl() возвращает путь с ведущим `/`, addCss/addJs ожидают путь без него.
     *
     * @param string $subdir Поддиректория (например 'css', 'js')
     * @return string Путь вида `wa-data/public/shop/plugins/{id}/{subdir}/`
     * @throws waException
     */
    private function getPublicDataPath(string $subdir): string
    {
        return substr(wa()->getDataUrl("plugins/{$this->plugin_id}/{$subdir}/", true, 'shop'), 1);
    }

    /**
     * Получает экземпляр waResponse (ленивая инициализация)
     *
     * @return waResponse
     * @throws waException
     */
    private function getResponse(): waResponse
    {
        return $this->response ??= wa()->getResponse();
    }
}
