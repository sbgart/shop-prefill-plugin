<?php

class shopPrefillPluginStorefrontSettingProvider extends shopPrefillPluginAbstractSettingProvider
{
    public function __construct()
    {
        parent::__construct(
            new shopPrefillPluginSettingsModel(),
            shopPrefillPluginSettingsConfig::create('storefront.settings')
        );
    }

    public function getSettings(string $storefront_code): array
    {
        // Плагин мог быть удалён или выключен уже после того, как интеграцию включили:
        // хранимое `true` не должно выглядеть работающей интеграцией ни в форме, ни в коде
        return shopPrefillPluginGeoIntegrations::sanitize(
            $this->validate($this->model->get($storefront_code))
        );
    }

    public function setSetting(string $storefront_code, $key, $value, $groups = null): void
    {
        $this->flattenSettings($key, $value, $groups, function ($name, $val, $g) use ($storefront_code) {
            $this->model->set($storefront_code, $name, $val, $g);
        });
    }

    public function saveSettings(string $storefront_code, array $settings = []): void
    {
        $entries = [];
        $collect = function ($name, $val, $g) use (&$entries) {
            $entries[] = ['name' => $name, 'value' => $val, 'groups' => $g];
        };

        // Тумблер без плагина в базу не попадает: администратор не увидит его в форме
        // (поле отсутствующего плагина не рендерится) и не сможет выключить обратно
        $settings = shopPrefillPluginGeoIntegrations::sanitize($settings);

        foreach ($settings as $key => $value) {
            $this->flattenSettings($key, $value, null, $collect);
        }

        $this->flattenSettings('update_time', time(), null, $collect);
        $this->flattenSettings('updated_by', wa()->getUser()->getId() ?? 0, null, $collect);

        $this->model->setBulk($storefront_code, $entries);

        $this->purgeOrphanedCustomTemplates($storefront_code);
        $this->syncCssFile($storefront_code, $settings);

        shopPrefillPluginLog::info('Storefront settings saved', [
            'storefront_code' => $storefront_code,
            'updated_by'      => wa()->getUser()->getId(),
        ]);
    }

    /**
     * Удаляет zen.groups.{delivery,payment}.custom_templates.<id> для инстансов доставки/оплаты,
     * которых больше нет в shop_plugin (issue-80#4). UI рисует шаблон только для существующих
     * методов, поэтому удалённый инстанс никогда не попадает в POST и без явной чистки строки
     * оставались бы в таблице навсегда.
     *
     * 'all' => true обязателен: выключенный (но не удалённый) метод не должен считаться
     * осиротевшим — listPlugins() без него отдаёт только status=1.
     *
     * @throws waException
     */
    private function purgeOrphanedCustomTemplates(string $storefront_code): void
    {
        $model = new shopPluginModel();

        $delivery_ids = array_map('strval', array_keys($model->listPlugins(shopPluginModel::TYPE_SHIPPING, ['all' => true])));
        $payment_ids  = array_map('strval', array_keys($model->listPlugins(shopPluginModel::TYPE_PAYMENT, ['all' => true])));

        $this->model->deleteOrphanedGroups($storefront_code, ['zen', 'groups', 'delivery', 'custom_templates'], $delivery_ids);
        $this->model->deleteOrphanedGroups($storefront_code, ['zen', 'groups', 'payment', 'custom_templates'], $payment_ids);
    }

    /**
     * Синхронизирует CSS-файл на диске с сохранённым custom_css.
     * Вызывается только если в $settings передан ключ styles.custom_css.
     *
     * @throws waException
     */
    private function syncCssFile(string $storefront_code, array $settings): void
    {
        $custom_css = $settings['styles']['custom_css'] ?? null;

        if ($custom_css === null) {
            return;
        }

        shopPrefillPlugin::getInstance()->getCssManager()->saveFile($storefront_code, $custom_css);
    }
}
