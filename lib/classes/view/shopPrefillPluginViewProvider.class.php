<?php

class shopPrefillPluginViewProvider
{
    /**
     * @throws waException
     * @throws SmartyException
     */
    public static function render(string $path, array $params = []): string
    {
        $view = wa()->getView();

        $params['plugin_url'] = shopPrefillPlugin::getStaticUrl();

        $view_path = shopPrefillPlugin::getPluginPath() . '/templates/' . $path;

        return self::withScopedVars($view, $params, static function () use ($view, $view_path) {
            return $view->fetch($view_path . '.html');
        });
    }

    /**
     * Временно подставляет $vars в общий (singleton) waView, выполняет $render и
     * восстанавливает исходные значения переменных — иначе они «утекают» в шаблоны
     * темы/других плагинов, рендерящихся тем же view позже в этом же запросе.
     *
     * Восстановление — в finally: значения не должны потеряться, даже если $render бросит.
     *
     * @throws Exception
     */
    public static function withScopedVars(waView $view, array $vars, callable $render): string
    {
        $old_vars = [];
        foreach ($vars as $key => $value) {
            if (isset($view->getVars()[$key])) {
                $old_vars[$key] = $view->getVars()[$key];
            }
        }

        $view->assign($vars);

        try {
            return $render();
        } finally {
            $view->assign($old_vars);
            foreach ($vars as $key => $value) {
                if (! isset($old_vars[$key])) {
                    $view->clearAssign($key);
                }
            }
        }
    }
}
