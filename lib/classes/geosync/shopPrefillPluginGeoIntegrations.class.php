<?php

/**
 * Реестр интеграций со сторонними плагинами: какие вообще бывают и какие сейчас имеют смысл.
 *
 * Тумблер интеграции без установленного плагина ничего не значит и опасен: администратор
 * включает «на будущее» или удаляет плагин уже после включения, а в настройках витрины
 * остаётся `true`, будто интеграция работает. Поэтому доступность плагина здесь —
 * единственный источник правды и для формы настроек, и для записи, и для чтения.
 *
 * `shopPrefillPlugin::enableInstall()` отвечает сразу на оба вопроса: `waAppConfig::getPlugins()`
 * пропускает и плагины, выключенные в `wa-config/apps/shop/plugins.php`, и те, чьи файлы
 * удалены с диска (проверка `file_exists()` на `lib/config/plugin.php`).
 */
class shopPrefillPluginGeoIntegrations
{
    public const CITYSELECT = 'cityselect';
    public const REGIONS    = 'regions';
    public const DP         = 'dp';

    /** @var array<string, bool>|null Доступность плагинов, кэш на запрос */
    private static ?array $availability = null;

    /**
     * @return string[] Идентификаторы плагинов, с которыми умеем интегрироваться
     */
    public static function ids(): array
    {
        return [self::CITYSELECT, self::REGIONS, self::DP];
    }

    /**
     * Плагины, которые сами хранят город покупателя. `dp` сюда не входит: собственного
     * города у него нет, он только получает наш вместе с cityselect.
     *
     * @return string[]
     */
    public static function cityProviderIds(): array
    {
        return [self::CITYSELECT, self::REGIONS];
    }

    /**
     * Хоть один плагин выбора города установлен и включён. Самая дешёвая из проверок:
     * читает файловый кэш конфига приложения, в базу не ходит.
     */
    public static function hasAnyCityProvider(): bool
    {
        foreach (self::cityProviderIds() as $plugin_id) {
            if (self::isPluginAvailable($plugin_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Плагин установлен на диске и включён в приложении.
     */
    public static function isPluginAvailable(string $plugin_id): bool
    {
        return self::getAvailability()[$plugin_id] ?? false;
    }

    /**
     * @return array<string, bool> plugin_id => доступен ли
     */
    public static function getAvailability(): array
    {
        if (self::$availability !== null) {
            return self::$availability;
        }

        $availability = [];
        foreach (self::ids() as $plugin_id) {
            $availability[$plugin_id] = shopPrefillPlugin::enableInstall($plugin_id);
        }

        return self::$availability = $availability;
    }

    /**
     * Интеграция включена И плагин, ради которого она существует, на месте.
     *
     * @param array<string, mixed> $settings Настройки эффективной витрины
     */
    public static function isEnabled(array $settings, string $plugin_id): bool
    {
        if (empty($settings['prefill']['integration'][$plugin_id])) {
            return false;
        }

        return self::isPluginAvailable($plugin_id);
    }

    /**
     * Хоть одна интеграция включена и работоспособна — дешёвая проверка перед тем,
     * как поднимать гео-синхронизацию.
     *
     * @param array<string, mixed> $settings Настройки эффективной витрины
     */
    public static function hasEnabled(array $settings): bool
    {
        foreach (self::ids() as $plugin_id) {
            if (self::isEnabled($settings, $plugin_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Гасит тумблеры, которым не соответствует установленный плагин.
     *
     * Применяется и на чтении, и на записи: на чтении — чтобы плагин, удалённый уже после
     * включения интеграции, не оставлял её формально включённой; на записи — чтобы в базе
     * не оседало `true`, которого администратор не увидит в форме (тумблер отсутствующего
     * плагина не рендерится) и не сможет выключить.
     *
     * Ветка `prefill` целиком отсутствует у частичных сохранений — их не трогаем.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function sanitize(array $settings): array
    {
        if (! isset($settings['prefill']) || ! is_array($settings['prefill'])) {
            return $settings;
        }

        foreach (self::ids() as $plugin_id) {
            if (self::isPluginAvailable($plugin_id)) {
                continue;
            }

            $settings['prefill']['integration'][$plugin_id] = false;
        }

        return $settings;
    }

    /**
     * Сбрасывает кэш доступности. Нужен тестам и сценариям, где плагин включают
     * в том же процессе, в котором уже читали настройки.
     */
    public static function clearCache(): void
    {
        self::$availability = null;
    }
}
