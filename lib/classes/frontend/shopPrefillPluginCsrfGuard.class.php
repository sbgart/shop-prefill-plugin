<?php

/**
 * Проверка «запрос пришёл со своего же сайта» для публичных POST-эндпоинтов плагина.
 *
 * Ядро проверяет _csrf только на secure-маршрутах (waDispatch.class.php), а роуты
 * плагина не secure — см. docs/codereview/issue-79-issue-52-csrf-half-done.md.
 * Sec-Fetch-Site/Origin/Referer работают и для анонимного гостя без сессии: кука
 * _csrf ставится ядром (waAuthUser::init()) только уже авторизованным контактам,
 * поэтому для первого визита гостя (например, до согласия на хранение данных)
 * токена ещё не существует и сверять нечего.
 */
class shopPrefillPluginCsrfGuard
{
    public static function isSameOriginRequest(): bool
    {
        $host = (string) waRequest::server('HTTP_HOST', '', waRequest::TYPE_STRING_TRIM);
        if ($host === '') {
            return false;
        }

        $sec_fetch_site = (string) waRequest::server('HTTP_SEC_FETCH_SITE', '', waRequest::TYPE_STRING_TRIM);
        if ($sec_fetch_site !== '') {
            return in_array($sec_fetch_site, ['same-origin', 'none'], true);
        }

        $origin = (string) waRequest::server('HTTP_ORIGIN', '', waRequest::TYPE_STRING_TRIM);
        if ($origin !== '') {
            return self::hostMatches($origin, $host);
        }

        $referer = (string) waRequest::server('HTTP_REFERER', '', waRequest::TYPE_STRING_TRIM);
        if ($referer !== '') {
            return self::hostMatches($referer, $host);
        }

        // Ни одного заголовка (старый браузер либо Referrer-Policy их срезала) —
        // последний резерв: кука _csrf, если она уже есть. У гостя без неё пропускаем
        // запрос, как и сегодня без всякой защиты — не регресс, а тот же уровень риска.
        if ((string) waRequest::cookie('_csrf', '', waRequest::TYPE_STRING_TRIM) === '') {
            return true;
        }

        return self::matchesCsrfToken();
    }

    /**
     * Сверяет _csrf из POST с _csrf-кукой. Используется как основная (жёсткая, без
     * исключений) проверка в shopPrefillPluginFrontendDebugBaseController — там она
     * гарантированно применима (доступ только админу, у которого кука уже есть), и
     * как резервная здесь, в isSameOriginRequest(), когда заголовки недоступны.
     */
    public static function matchesCsrfToken(): bool
    {
        $cookie = (string) waRequest::cookie('_csrf', '', waRequest::TYPE_STRING_TRIM);
        $sent = (string) waRequest::post('_csrf', '', waRequest::TYPE_STRING_TRIM);
        return $sent !== '' && hash_equals($cookie, $sent);
    }

    private static function hostMatches(string $url, string $host): bool
    {
        $url_host = parse_url($url, PHP_URL_HOST);
        return is_string($url_host) && $url_host !== '' && strcasecmp($url_host, self::stripPort($host)) === 0;
    }

    private static function stripPort(string $host): string
    {
        $pos = strrpos($host, ':');
        return $pos === false ? $host : substr($host, 0, $pos);
    }
}
