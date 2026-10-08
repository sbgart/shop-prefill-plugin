<?php

/** Обновляет снимок текущего состояния без чтения истории заказов. */
class shopPrefillPluginFrontendRefreshDebugController extends shopPrefillPluginFrontendDebugBaseController
{
    protected function handle()
    {
        try {
            waLocale::loadByDomain(['shop', 'prefill']);
            waSystem::pushActivePlugin('prefill', 'shop');
            $plugin = shopPrefillPlugin::getInstance();

            $vars = shopPrefillPluginDebug::collectCurrentState($plugin);
            $vars['initial_events'] = [];
            $vars['request_id'] = '';
            $vars['request_type'] = 'snapshot';
            $this->response = [
                'status' => 'ok',
                'html' => shopPrefillPluginViewProvider::render('debug/DebugState', $vars),
                'timestamp' => date('H:i:s'),
            ];
        } catch (Exception $e) {
            shopPrefillPluginLog::error('Failed refreshing Prefill debug panel', ['message' => $e->getMessage()]);
            $this->errors = ['error' => $e->getMessage()];
        }
    }
}
