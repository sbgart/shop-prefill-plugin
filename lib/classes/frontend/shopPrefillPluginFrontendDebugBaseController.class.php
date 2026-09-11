<?php

/**
 * Базовый класс публичных debug-эндпоинтов чекаута.
 *
 * Общая проверка для всех действий debug-панели: только POST, только при
 * включённом глобальном debug, только администратор магазина, с CSRF-токеном
 * ядра (кука `_csrf`, сверяется с тем же значением из тела запроса).
 */
abstract class shopPrefillPluginFrontendDebugBaseController extends waJsonController
{
    final public function execute()
    {
        if (!$this->isAllowed()) {
            $this->errors = 'Access denied';
            return;
        }

        $this->handle();
    }

    abstract protected function handle();

    private function isAllowed(): bool
    {
        $sent = (string) waRequest::post('_csrf', '', waRequest::TYPE_STRING_TRIM);
        $cookie = (string) waRequest::cookie('_csrf', '', waRequest::TYPE_STRING_TRIM);
        return waRequest::method() === 'post'
            && shopPrefillPlugin::getInstance()->isDebug()
            && wa()->getUser()->isAdmin('shop')
            && $sent !== ''
            && hash_equals($cookie, $sent);
    }
}
