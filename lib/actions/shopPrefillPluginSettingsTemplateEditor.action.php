<?php

/**
 * AJAX-action: рендерит содержимое модального окна редактора шаблона.
 * Вызывается через ?module=prefillPluginSettingsTemplateEditor.
 * Возвращает HTML (waViewAction), который JS вставляет в $.waDialog.
 */
class shopPrefillPluginSettingsTemplateEditorAction extends waViewAction
{
    /**
     * Определяет, какие группы полей (из getAvailableFields) показывать
     * в редакторе для каждого типа шаблона.
     * Порядок в массиве = порядок секций в UI.
     */
    private const EDITOR_FIELD_GROUPS = [
        'delivery' => ['delivery', 'address', 'contact'],
        'payment'  => ['payment', 'contact'],
    ];

    /**
     * @throws waException
     */
    public function execute()
    {
        $group = waRequest::post('group', '', waRequest::TYPE_STRING_TRIM);

        if (!array_key_exists($group, self::EDITOR_FIELD_GROUPS)) {
            throw new waException('Invalid group', 400);
        }

        // Locale config
        waLocale::loadByDomain(['shop', 'prefill']);
        waSystem::pushActivePlugin('prefill', 'shop');

        $this->view->assign([
            'group'       => $group,
            'default_tpl' => shopPrefillPluginZenData::getDefaultTemplate($group),
            'var_groups'  => $this->buildVarGroups($group),
            'conditions'  => $this->buildConditions(),
            'formatting'  => $this->buildFormattingSnippets(),
        ]);
    }

    /**
     * Строит секции переменных для модального редактора из единого источника правды —
     * shopPrefillPluginZenData::getAvailableFields().
     * Каждая секция соответствует одной группе полей (delivery, address, contact, payment).
     *
     * @param string $editor_group 'delivery'|'payment'
     * @return array[]
     */
    private function buildVarGroups(string $editor_group): array
    {
        // Метки секций в UI (ключ = значение поля 'group' в getAvailableFields)
        $group_labels = [
            'delivery' => _wp('zen.custom_template.vars.delivery'),
            'address'  => _wp('zen.custom_template.vars.address'),
            'contact'  => _wp('zen.custom_template.vars.customer'),
            'payment'  => _wp('zen.custom_template.vars.payment'),
        ];

        // Индексируем все поля по group-значению
        $fields_by_group = [];
        foreach (shopPrefillPluginZenData::getAvailableFields() as $key => $field) {
            $fields_by_group[$field['group']][] = [
                'snippet'     => '{$' . $key . '}',
                'name'        => $field['name'],
                'description' => $field['description'],
            ];
        }

        // Собираем только те группы, которые нужны этому типу редактора, в нужном порядке
        $var_groups = [];
        foreach (self::EDITOR_FIELD_GROUPS[$editor_group] as $field_group) {
            if (!empty($fields_by_group[$field_group])) {
                $var_groups[] = [
                    'label' => $group_labels[$field_group],
                    'vars'  => $fields_by_group[$field_group],
                ];
            }
        }

        return $var_groups;
    }

    /**
     * Сниппеты условий Smarty.
     * Строки PHP не переинтерпретируются Smarty при выводе через {$var}.
     *
     * @return array[]
     */
    private function buildConditions(): array
    {
        return [
            ['label' => '{if}…{/if}',         'snippet' => '{if $}{/if}'],
            ['label' => '{if}…{else}…{/if}',  'snippet' => '{if $}{else}{/if}'],
            ['label' => '{if $a && $b}',      'snippet' => '{if $ && $}{/if}'],
            ['label' => '{if $a || $b}',      'snippet' => '{if $ || $}{/if}'],
        ];
    }

    /**
     * Базовые HTML/форматирующие вставки для шаблонов.
     *
     * @return array[]
     */
    private function buildFormattingSnippets(): array
    {
        return [
            ['label' => _wp('zen.custom_template.format.bold'),      'snippet' => '<strong></strong>'],
            ['label' => _wp('zen.custom_template.format.italic'),    'snippet' => '<em></em>'],
            ['label' => _wp('zen.custom_template.format.underline'), 'snippet' => '<u></u>'],
            ['label' => _wp('zen.custom_template.format.br'),        'snippet' => '<br />'],
            ['label' => _wp('zen.custom_template.format.bullet'),    'snippet' => ' &bull; '],
            ['label' => _wp('zen.custom_template.format.link'),      'snippet' => '<a href=\"\"></a>'],
        ];
    }
}
