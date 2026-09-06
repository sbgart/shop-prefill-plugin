<?php

class shopPrefillPluginLog
{
    public const LOG_FILE = 'prefill.plugin.log';
    public const ERROR_LOG_FILE = 'prefill.plugin.error.log';

    /** Суффикс ротированного файла. Храним одно поколение: prefill.plugin.log.1 */
    public const ROTATED_SUFFIX = '.1';

    /** Порог ротации в байтах */
    private const MAX_FILE_SIZE = 5242880; // 5 MB

    /** Максимальная длина сериализованного контекста в символах */
    private const MAX_CONTEXT_LENGTH = 4096;

    private static ?string $configured_level = null;

    /** Защита от рекурсии: чтение настроек само не должно логировать через debug()/info() */
    private static bool $loading_level = false;

    /** Файлы, уже проверенные на ротацию в этом запросе */
    private static array $rotation_checked = [];

    /**
     * Явная установка уровня — вызывается из isActive() сразу после загрузки настроек
     * и из контроллера сохранения настройки, чтобы новый уровень подействовал в том же запросе.
     * Не единственный источник: getLevel() подтягивает уровень сам, если его ещё не выставили.
     */
    public static function setLevel(string $level): void
    {
        $valid = ['off', 'error', 'warning', 'info', 'debug'];
        self::$configured_level = in_array($level, $valid, true) ? $level : 'warning';
    }

    /** Фактически применяемый уровень для диагностического интерфейса. */
    public static function getConfiguredLevel(): string
    {
        return self::getLevel();
    }

    public static function debug($message, $context = null): void
    {
        if (!self::isLevelEnabled('debug')) {
            return;
        }
        self::writeLog($message, $context, self::LOG_FILE, 'DEBUG');
    }

    public static function info($message, $context = null): void
    {
        if (!self::isLevelEnabled('info')) {
            return;
        }
        self::writeLog($message, $context, self::LOG_FILE, 'INFO');
    }

    public static function warning($message, $context = null): void
    {
        if (!self::isLevelEnabled('warning')) {
            return;
        }
        self::writeLog($message, $context, self::ERROR_LOG_FILE, 'WARNING');
    }

    public static function error($message, $context = null): void
    {
        if (!self::isLevelEnabled('error')) {
            return;
        }
        self::writeLog($message, $context, self::ERROR_LOG_FILE, 'ERROR');
    }

    /**
     * До 22.08.2026 уровень выставлялся только как побочный эффект isActive(), а её
     * зовут исключительно точки входа хуков (см. shopPrefill.plugin.php). Собственные
     * роуты плагина (lib/config/routing.php → frontend/*) хуки не проходят и читают
     * настройки напрямую — level оставался на дефолте 'warning', debug/info молчали
     * (issue-81). Теперь getLevel() подтягивает настройку сама при первом обращении —
     * чтобы это работало для любой, в том числе будущей, точки входа.
     */
    private static function getLevel(): string
    {
        if (self::$configured_level === null && !self::$loading_level) {
            self::$loading_level = true;
            try {
                self::$configured_level = self::loadLevelFromSettings();
            } finally {
                self::$loading_level = false;
            }
        }

        return self::$configured_level ?? 'warning';
    }

    /**
     * Читает уровень логирования напрямую из настроек плагина, в обход isActive().
     *
     * Осторожно с рекурсией: этот путь сам не должен логировать через debug()/info() —
     * иначе getLevel() позовёт себя же, пока self::$configured_level ещё не установлен.
     * Сейчас цепочка getSettingProvider()->getSettings() ничего не логирует; $loading_level
     * — страховка на случай, если это перестанет быть так.
     */
    private static function loadLevelFromSettings(): string
    {
        try {
            $settings = shopPrefillPlugin::getInstance()->getSettingProvider()->getSettings();
        } catch (Throwable $e) {
            return 'warning';
        }

        $level = $settings['logging']['level'] ?? 'warning';
        $valid = ['off', 'error', 'warning', 'info', 'debug'];

        return in_array($level, $valid, true) ? $level : 'warning';
    }

    private static function isLevelEnabled(string $level): bool
    {
        $order = ['off' => 0, 'error' => 1, 'warning' => 2, 'info' => 3, 'debug' => 4];
        $configured_num = $order[self::getLevel()] ?? 2;
        $level_num = $order[$level] ?? 4;
        return $level_num <= $configured_num;
    }

    private static function writeLog($message, $context, $file, $level): void
    {
        $log_path = wa()->getConfig()->getPath('log') . '/' . $file;

        self::rotateIfNeeded($log_path);

        // IP: X-Forwarded-For имеет приоритет (как в waLog)
        $ip = !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
            : ($_SERVER['REMOTE_ADDR'] ?? 'cli');

        $header = date('Y-m-d H:i:s') . ' ' . $ip;

        // Добавляем ID авторизованного пользователя к строке-заголовку
        try {
            $user_id = wa()->getUser()->getId();
            if ($user_id > 0) {
                $header .= ' user:' . $user_id;
            }
        } catch (Exception $e) {
            // CLI или контекст без пользователя — пропускаем
        }

        $body = "[{$level}] {$message}";
        if ($context !== null) {
            $body .= "\nContext: " . self::formatContext($context);
        }

        file_put_contents($log_path, "\n{$header}\n{$body}\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Сериализует контекст и обрезает его по длине.
     *
     * В контекст попадают данные из публичных фронтовых эндпоинтов, длину которых
     * задаёт клиент: без ограничения одна запись может весить мегабайты.
     *
     * Обрезанный JSON перестаёт разбираться, и просмотрщик покажет его как обычный
     * текст — это штатная ветка `json_last_error()` в shopPrefillPluginLogReader.
     */
    private static function formatContext($context): string
    {
        if (is_array($context) || is_object($context)) {
            $text = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            // json_encode отдаёт false на битом UTF-8 — не молчим, иначе контекст исчезнет
            if ($text === false) {
                return '[not encodable: ' . json_last_error_msg() . ']';
            }
        } else {
            $text = (string) $context;
        }

        if (mb_strlen($text) <= self::MAX_CONTEXT_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_CONTEXT_LENGTH) . '… [truncated]';
    }

    /**
     * Ротация по размеру: prefill.plugin.log → prefill.plugin.log.1
     *
     * Фронтовые эндпоинты плагина публичны и отвечают без авторизации, поэтому объём
     * записей задаёт кто угодно: без ограничения файл растёт до заполнения диска.
     *
     * Проверяем один раз за запрос — за один запрос файл не вырастет на MAX_FILE_SIZE,
     * а stat() на каждой строке debug-лога это лишняя работа на горячем пути.
     */
    private static function rotateIfNeeded(string $log_path): void
    {
        if (isset(self::$rotation_checked[$log_path])) {
            return;
        }
        self::$rotation_checked[$log_path] = true;

        // Без сброса получим размер, закэшированный до записей этого запроса
        clearstatcache(true, $log_path);

        if (!is_file($log_path) || filesize($log_path) < self::MAX_FILE_SIZE) {
            return;
        }

        // rename() атомарен и молча перетирает предыдущее поколение.
        // Ошибку глушим намеренно: логирование не должно ронять запрос из-за прав на файл
        @rename($log_path, $log_path . self::ROTATED_SUFFIX);
    }
}
