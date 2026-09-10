<?php

class shopPrefillPluginStorefrontSettingProvider extends shopPrefillPluginAbstractSettingProvider
{
    // Ветки zen.groups.{customer,delivery,payment}, чьи листья рендерятся Smarty без
    // enableSecurity() (RULES.md B4) — исполнение произвольного PHP, а не текстовый шаблон
    private const TEMPLATE_GROUPS = ['customer', 'delivery', 'payment'];
    private const TEMPLATE_LEAVES = ['summary_template', 'custom_templates'];

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

    public function saveSettings(string $storefront_code, array $settings = []): void
    {
        $settings = $this->filterKnown($settings);
        $settings = $this->stripTemplateWritesForNonAdmin($settings);

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
     * Гейт на запись, которого не было (issue-96). Форма плагина сохраняется ядровым
     * `?module=plugins&action=save` под правом `shop:settings` — гранулярным, не следующим
     * из isAdmin() и выдаваемым отдельно (shopPluginsActions::preExecute()). Сам редактор
     * шаблона (SettingsAction/TemplateEditor/TemplatePreview) уже требует isAdmin(APP_ID), но
     * этот путь сохранения — нет: без проверки здесь владелец только `settings` мог записать
     * summary_template/custom_templates, не имея доступа даже открыть их форму. Остальные поля
     * (цвета, тумблеры) не трогаем — их запрет сломал бы штатную работу с правом `settings`.
     */
    private function stripTemplateWritesForNonAdmin(array $settings): array
    {
        if (wa()->getUser()->isAdmin(shopPrefillPlugin::APP_ID)) {
            return $settings;
        }

        foreach (self::TEMPLATE_GROUPS as $group) {
            foreach (self::TEMPLATE_LEAVES as $leaf) {
                unset($settings['zen']['groups'][$group][$leaf]);
            }
        }

        return $settings;
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
