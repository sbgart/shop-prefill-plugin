<?php

/**
 * Управляет per-storefront CSS-файлами.
 *
 * Исходник: css/frontend.css в плагине (не трогается).
 * Рабочий файл: wa-data/public/shop/plugins/prefill/css/frontend_{code}.css
 *
 * Cache busting — через ?{update_time} в URL, как это принято в Webasyst.
 * Источник истины — значение styles.custom_css в shop_prefill_settings.
 */
class shopPrefillPluginCssManager
{
    private string $plugin_id;
    private string $plugin_path;

    public function __construct(string $plugin_id, string $plugin_path)
    {
        $this->plugin_id   = $plugin_id;
        $this->plugin_path = $plugin_path;
    }

    /**
     * Читает оригинальный frontend.css из директории плагина.
     */
    public function getDefaultContent(): string
    {
        $path = "{$this->plugin_path}/css/frontend.css";
        return file_exists($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Записывает per-storefront CSS-файл на диск.
     * Если содержимое пустое — удаляет файл.
     *
     * @throws waException
     */
    public function saveFile(string $storefront_code, string $content): void
    {
        if ($content === '') {
            $this->deleteFile($storefront_code);
            return;
        }

        $dir = $this->getPublicDir();
        if (!is_dir($dir)) {
            waFiles::create($dir);
        }

        $path     = $this->getFilePath($storefront_code);
        $is_new   = !file_exists($path);
        $result   = file_put_contents($path, $content);

        if ($result === false) {
            shopPrefillPluginLog::error('CSS file write failed', [
                'storefront_code' => $storefront_code,
                'path'            => $path,
            ]);
            return;
        }

        if ($is_new) {
            shopPrefillPluginLog::info('CSS file created', [
                'storefront_code' => $storefront_code,
                'size'            => strlen($content),
            ]);
        } else {
            shopPrefillPluginLog::debug('CSS file updated', [
                'storefront_code' => $storefront_code,
                'size'            => strlen($content),
            ]);
        }
    }

    /**
     * Удаляет per-storefront CSS-файл (при сбросе к оригиналу).
     *
     * @throws waException
     */
    public function deleteFile(string $storefront_code): void
    {
        $path = $this->getFilePath($storefront_code);
        if (!file_exists($path)) {
            shopPrefillPluginLog::debug('CSS file delete skipped: file not found', [
                'storefront_code' => $storefront_code,
            ]);
            return;
        }

        if (unlink($path)) {
            shopPrefillPluginLog::info('CSS file deleted (reset to default)', [
                'storefront_code' => $storefront_code,
            ]);
        } else {
            shopPrefillPluginLog::error('CSS file delete failed', [
                'storefront_code' => $storefront_code,
                'path'            => $path,
            ]);
        }
    }

    /**
     * Проверяет наличие per-storefront файла на диске.
     *
     * @throws waException
     */
    public function fileExists(string $storefront_code): bool
    {
        return file_exists($this->getFilePath($storefront_code));
    }

    /**
     * Возвращает публичный URL per-storefront файла с cache-buster.
     * $update_time — значение update_time из настроек витрины.
     *
     * @throws waException
     */
    public function getPublicUrl(string $storefront_code, int $update_time = 0): string
    {
        $safe_code = $this->sanitizeCode($storefront_code);
        $url = wa()->getDataUrl(
            "plugins/{$this->plugin_id}/css/frontend_{$safe_code}.css",
            true,
            'shop'
        );

        return $update_time > 0 ? "{$url}?{$update_time}" : $url;
    }

    /**
     * Возвращает абсолютный путь к файлу на диске.
     *
     * @throws waException
     */
    public function getFilePath(string $storefront_code): string
    {
        $safe_code = $this->sanitizeCode($storefront_code);
        return "{$this->getPublicDir()}frontend_{$safe_code}.css";
    }

    /**
     * @throws waException
     */
    private function getPublicDir(): string
    {
        return wa()->getDataPath("plugins/{$this->plugin_id}/css/", true, 'shop');
    }

    private function sanitizeCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $code);
    }
}
