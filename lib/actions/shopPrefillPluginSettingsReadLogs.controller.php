<?php

/**
 * AJAX: читает оба лог-файла плагина, объединяет и сортирует по времени.
 * Доступен по ?module=prefillPluginSettingsReadLogs
 */
class shopPrefillPluginSettingsReadLogsController extends shopPrefillPluginSettingsBaseController
{
    private const PAGE_SIZE = 150;

    protected function handle(): void
    {
        waLocale::loadByDomain(['shop', 'prefill']);
        waSystem::pushActivePlugin('prefill', 'shop');

        // readMerged возвращает записи по возрастанию (oldest first)
        $all   = shopPrefillPluginLogReader::readMerged();
        $total = count($all);
        $all_reversed = array_reverse($all); // newest first

        // Счётчики по уровню — всегда возвращаем для обновления значков в сайдбаре
        $counts = ['debug' => 0, 'info' => 0, 'warning' => 0, 'error' => 0];
        foreach ($all as $entry) {
            if (isset($counts[$entry['level']])) {
                $counts[$entry['level']]++;
            }
        }

        $level = strtolower(trim(waRequest::get('level', '')));
        $valid_levels = ['debug', 'info', 'warning', 'error'];

        $limit  = max(10, min(500, (int) waRequest::get('limit', self::PAGE_SIZE)));
        $offset = max(0, (int) waRequest::get('offset', 0));

        if ($level && in_array($level, $valid_levels, true)) {
            // Серверная фильтрация по уровню с пагинацией
            $level_all = array_values(array_filter($all_reversed, static fn($e) => $e['level'] === $level));
            $entries   = array_slice($level_all, $offset, $limit);
            $this->response = [
                'entries'  => $entries,
                'total'    => $total,
                'counts'   => $counts,
                'level'    => $level,
                'has_more' => ($offset + $limit) < count($level_all),
            ];
            return;
        }
        $entries = array_slice($all_reversed, $offset, $limit);

        $this->response = [
            'entries'  => array_values($entries),
            'total'    => $total,
            'counts'   => $counts,
            'offset'   => $offset,
            'limit'    => $limit,
            'has_more' => ($offset + $limit) < $total,
        ];
    }
}
