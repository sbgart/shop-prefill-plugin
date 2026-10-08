<?php

/**
 * Базовый класс публичных debug-эндпоинтов чекаута.
 *
 * Единый контракт для всех запросов debug-панели — и изменяющих (сброс, перезаполнение),
 * и только читающих (снимок состояния, источник, серверный лог): только POST, только
 * когда панель доступна текущему пользователю (shopPrefillPlugin::isDebugPanelEnabled() —
 * настройка витрины плюс полный доступ к магазину), с CSRF-токеном ядра (кука `_csrf`,
 * сверяется с тем же значением из тела запроса — shopPrefillPluginCsrfGuard::matchesCsrfToken()).
 *
 * Читающие эндпоинты раньше гейтились отдельно и слабее (без прав и CSRF) и отдавали
 * снимок сессии анониму — поэтому теперь все наследуют этот класс, а не waJsonController.
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
            && shopPrefillPlugin::getInstance()->isDebugPanelEnabled()
            && shopPrefillPluginCsrfGuard::matchesCsrfToken();
    }
}
