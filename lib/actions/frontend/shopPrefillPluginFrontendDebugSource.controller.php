<?php

/** Явно читает источник истории для просмотра, ничего не применяя к checkout. */
class shopPrefillPluginFrontendDebugSourceController extends shopPrefillPluginFrontendDebugBaseController
{
    protected function handle()
    {
        try {
            waLocale::loadByDomain(['shop', 'prefill']);
            waSystem::pushActivePlugin('prefill', 'shop');
            $plugin = shopPrefillPlugin::getInstance();

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
