<?php

/**
 * AJAX-action: рендерит содержимое модального окна редактора шаблона.
 * Вызывается через ?module=prefillPluginSettingsTemplateEditor.
 * Возвращает HTML (waViewAction), который JS вставляет в $.waDialog.
 */
class shopPrefillPluginSettingsTemplateEditorAction extends shopPrefillPluginSettingsBaseAction
{
    /**
     * @throws waException
     */
    protected function handle()
    {
        $group = waRequest::post('group', '', waRequest::TYPE_STRING_TRIM);

        if (!array_key_exists($group, shopPrefillPluginZenData::TEMPLATE_EDITOR_FIELD_GROUPS)) {
            throw new waException('Invalid group', 400);
        }

        // Locale config
        waLocale::loadByDomain(['shop', 'prefill']);
        waSystem::pushActivePlugin('prefill', 'shop');

        $this->view->assign([
            'group'              => $group,
            'default_tpl'        => shopPrefillPluginZenData::getDefaultTemplate($group),
            'var_groups'         => $this->buildVarGroups($group),
            'conditions'         => $this->buildConditions(),
            'formatting'         => $this->buildFormattingSnippets(),
            'template_editor_ui' => [
                'tooltip_example'   => _wp('zen.custom_template.tooltip.example'),
                'insert_loop'       => _wp('zen.custom_template.insert.loop'),
                'insert_variable'   => _wp('zen.custom_template.insert.variable'),
            ],
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
            $row = [
                'snippet'       => '{$' . $key . '}',
                'name'          => $field['name'],
                'description'   => $field['description'],
                'example'       => $field['example'],
                'is_array'      => !empty($field['is_array']),
            ];
            if (!empty($field['snippet_loop'])) {
                $row['snippet_loop'] = $field['snippet_loop'];
            }
            if (!empty($field['example_code'])) {
                $row['example_code'] = $field['example_code'];
            }
            $fields_by_group[$field['group']][] = $row;
        }

        // Собираем только те группы, которые нужны этому типу редактора, в нужном порядке
        $var_groups = [];
        foreach (shopPrefillPluginZenData::TEMPLATE_EDITOR_FIELD_GROUPS[$editor_group] as $field_group) {
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
            [
                'label'       => '{if}…{/if}',
                'snippet'     => '{if $}{/if}',
                'description' => _wp('zen.custom_template.tooltip.cond_if.desc'),
                'example'     => _wp('zen.custom_template.tooltip.cond_if.example'),
            ],
            [
                'label'       => '{if}…{else}…{/if}',
                'snippet'     => '{if $}{else}{/if}',
                'description' => _wp('zen.custom_template.tooltip.cond_else.desc'),
                'example'     => _wp('zen.custom_template.tooltip.cond_else.example'),
            ],
            [
                'label'       => '{if $a && $b}',
                'snippet'     => '{if $ && $}{/if}',
                'description' => _wp('zen.custom_template.tooltip.cond_and.desc'),
                'example'     => _wp('zen.custom_template.tooltip.cond_and.example'),
            ],
            [
                'label'       => '{if $a || $b}',
                'snippet'     => '{if $ || $}{/if}',
                'description' => _wp('zen.custom_template.tooltip.cond_or.desc'),
                'example'     => _wp('zen.custom_template.tooltip.cond_or.example'),
            ],
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
            [
                'label'       => _wp('zen.custom_template.format.bold'),
                'snippet'     => '<strong></strong>',
                'description' => _wp('zen.custom_template.tooltip.fmt_strong.desc'),
                'example'     => _wp('zen.custom_template.tooltip.fmt_strong.example'),
            ],
            [
                'label'       => _wp('zen.custom_template.format.italic'),
                'snippet'     => '<em></em>',
                'description' => _wp('zen.custom_template.tooltip.fmt_em.desc'),
                'example'     => _wp('zen.custom_template.tooltip.fmt_em.example'),
            ],
            [
                'label'       => _wp('zen.custom_template.format.underline'),
                'snippet'     => '<u></u>',
                'description' => _wp('zen.custom_template.tooltip.fmt_u.desc'),
                'example'     => _wp('zen.custom_template.tooltip.fmt_u.example'),
            ],
            [
                'label'       => _wp('zen.custom_template.format.br'),
                'snippet'     => '<br />',
                'description' => _wp('zen.custom_template.tooltip.fmt_br.desc'),
                'example'     => _wp('zen.custom_template.tooltip.fmt_br.example'),
            ],
            [
                'label'       => _wp('zen.custom_template.format.bullet'),
                'snippet'     => ' &bull; ',
                'description' => _wp('zen.custom_template.tooltip.fmt_bullet.desc'),
                'example'     => _wp('zen.custom_template.tooltip.fmt_bullet.example'),
            ],
            [
                'label'       => _wp('zen.custom_template.format.link'),
                'snippet'     => '<a href=\"\"></a>',
                'description' => _wp('zen.custom_template.tooltip.fmt_a.desc'),
                'example'     => _wp('zen.custom_template.tooltip.fmt_a.example'),
            ],
        ];
    }
}
