<?php

/**
 * Базовый класс публичных debug-эндпоинтов чекаута.
 *
 * Общая проверка для всех действий debug-панели: только POST, только при
 * включённом глобальном debug, только администратор магазина, с CSRF-токеном
 * ядра (кука `_csrf`, сверяется с тем же значением из тела запроса —
 * shopPrefillPluginCsrfGuard::matchesCsrfToken()). Здесь проверка жёсткая, без
 * исключений — в отличие от isSameOriginRequest() публичных эндпоинтов, для
 * админа с включённым debug кука уже гарантированно есть.
 */
abstract class shopPrefillPluginFrontendDebugBaseController extends waJsonController
{
    final public function execute()
    {
        if (!$this->isAllowed()) {
            wa()->getResponse()->setStatus(403);
            $this->errors = 'Access denied';
            return;
        }

        $this->handle();
    }

    abstract protected function handle();

    private function isAllowed(): bool
    {
        return waRequest::method() === 'post'
            && shopPrefillPlugin::getInstance()->isDebug()
            && wa()->getUser()->isAdmin('shop')
            && shopPrefillPluginCsrfGuard::matchesCsrfToken();
    }
}
