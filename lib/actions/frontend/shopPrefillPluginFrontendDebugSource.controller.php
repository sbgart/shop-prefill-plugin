<?php

/** Явно читает источник истории для просмотра, ничего не применяя к checkout. */
class shopPrefillPluginFrontendDebugSourceController extends waJsonController
{
    public function execute()
    {
        try {
            waLocale::loadByDomain(['shop', 'prefill']);
            waSystem::pushActivePlugin('prefill', 'shop');
            $plugin = shopPrefillPlugin::getInstance();
            if (!$plugin->isDebugPanelEnabled()) {
                $this->errors = ['error' => 'Access denied'];
                return;
            }

            $vars = shopPrefillPluginDebug::loadSource($plugin);
            $this->response = [
                'status' => 'ok',
                'html' => shopPrefillPluginViewProvider::render('debug/DebugSource', $vars),
            ];
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Failed loading Prefill debug source', ['message' => $e->getMessage()]);
            $this->errors = ['error' => $e->getMessage()];
        }
    }
}
