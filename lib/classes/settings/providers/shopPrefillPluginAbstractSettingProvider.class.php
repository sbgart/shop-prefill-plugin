<?php

abstract class shopPrefillPluginAbstractSettingProvider
{
    protected shopPrefillPluginSettingsModel $model;
    protected shopPrefillPluginSettingsConfig $config;

    public function __construct(
        shopPrefillPluginSettingsModel $model,
        shopPrefillPluginSettingsConfig $config
    ) {
        $this->model  = $model;
        $this->config = $config;
    }

    protected function validate(array $settings): array
    {
        return $this->config->getSchema()->validate($settings);
    }

    /**
     * Рекурсивно разворачивает дерево настроек в плоский список листьев, вызывая
     * $on_leaf($name, $value, $groups) на каждый скаляр. Общая для setSetting() (пишет каждый
     * лист сразу) и saveSettings() (копит в буфер для SettingsModel::setBulk(), issue-74#5).
     *
     * @param mixed         $value
     * @param array|null    $groups путь групп, накопленный до этого уровня
     */
    protected function flattenSettings($key, $value, $groups, callable $on_leaf): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $this->flattenSettings($k, $v, array_merge((array) $groups, [$key]), $on_leaf);
            }
            return;
        }

        // bool → int so false isn't stored as empty string
        if (is_bool($value)) {
            $value = (int) $value;
        }

        $on_leaf($key, $value, $groups);
    }
}
