<?php

/**
 * Решает, потерял ли покупатель выбранный вариант доставки при пересчёте.
 *
 * Ядро молча обнуляет `variant_id`, которого нет в заново посчитанном списке
 * (shopCheckoutShippingStep), — ни ошибки, ни следа в ответе. Единственная улика —
 * эхо-кэш: он пишется из POST этого же запроса раньше, чем ядро выкинуло вариант, и
 * поэтому помнит то, чего в ответе уже нет. На следующем запросе секция пришлёт пустой
 * выбор, эхо будет стёрто как «покупатель сам оставил пустым» — потому и ловить надо
 * в том запросе, где вариант пропал.
 *
 * Чистая функция «факты → решение»: порядок веток запирается таблицей истинности
 * (tests/LostChoiceDetectorTest.php), а не надеждой на то, что правка его не переставит.
 *
 * См. docs/todo/zen-lost-variant-silent-expand.md
 */
class shopPrefillPluginLostChoiceDetector
{
    /**
     * @param array{echo_variant_id: ?string, step_skipped: bool, selected_variant_id: ?string, notified_variant_id?: ?string} $facts
     *        echo_variant_id     — вариант из эхо-кэша на момент рендера (null — эха нет);
     *        step_skipped        — шаг не считался (fast_render, короткое замыкание): список
     *                              пуст не потому, что вариант пропал, а потому что считать не стали;
     *        selected_variant_id — вариант, который ядро оставило выбранным в ответе;
     *        notified_variant_id — вариант, о потере которого покупателя уже предупредили.
     * @return array{lost: bool, reason: string}
     */
    public static function decide(array $facts): array
    {
        // Нечего терять: покупатель ничего не выбирал либо эхо сброшено (смена типа,
        // смена адреса, заказ создан). «Не выбрал» и «потерял» различает только эхо.
        if (($facts['echo_variant_id'] ?? null) === null || $facts['echo_variant_id'] === '') {
            return ['lost' => false, 'reason' => 'nothing_chosen'];
        }

        // Пустой ответ шага — не довод: при fast_render он пуст на каждой загрузке /order/
        if (!empty($facts['step_skipped'])) {
            return ['lost' => false, 'reason' => 'step_skipped'];
        }

        if (($facts['selected_variant_id'] ?? null) === null || $facts['selected_variant_id'] === '') {
            // Мёртвый вариант остаётся в сессии, и форма шлёт его заново на каждой загрузке
            // страницы: без этой ветки предупреждение повторялось бы до первого касания
            // покупателем доставки, а оно должно быть разовым.
            if (($facts['notified_variant_id'] ?? null) === $facts['echo_variant_id']) {
                return ['lost' => false, 'reason' => 'already_notified'];
            }

            return ['lost' => true, 'reason' => 'variant_missing_in_response'];
        }

        // Ядро оставило какой-то вариант выбранным (тот же или другой) — потери нет
        return ['lost' => false, 'reason' => 'variant_kept'];
    }
}
