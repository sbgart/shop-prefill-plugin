<?php

/**
 * AJAX-controller: рендерит превью Smarty-шаблона для Zen Mode (шаблон-строка).
 * Вызывается из модального редактора шаблонов через ?module=prefillPluginSettingsTemplatePreview.
 */
class shopPrefillPluginSettingsTemplatePreviewController extends waJsonController
{
    public function execute()
    {
        try {
            $group = waRequest::post('group', '', waRequest::TYPE_STRING_TRIM);
            if (!array_key_exists($group, shopPrefillPluginZenData::TEMPLATE_EDITOR_FIELD_GROUPS)) {
                throw new waException('Invalid group', 400);
            }

            $template = waRequest::post('template', '', waRequest::TYPE_STRING);

            waLocale::loadByDomain(['shop', 'prefill']);
            waSystem::pushActivePlugin('prefill', 'shop');

            $view = wa()->getView();

            $data = shopPrefillPluginZenData::getSampleData($group);

            $backup = $view->getVars();
            try {
                $view->clearAllAssign();
                $view->assign($data);
                $html = $view->fetch('string:' . $template);
            } finally {
                $view->clearAllAssign();
                $view->assign($backup);
            }

            $this->response = [
                'html' => $html,
            ];
        } catch (Throwable $e) {
            $this->errors[] = $e->getMessage();
        }
    }
}

