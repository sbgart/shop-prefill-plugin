<?php

/**
 * Решает, потерял ли покупатель выбранное при пересчёте, и какой из диалогов показать.
 *
 * Ядро молча выбрасывает выбор, которого нет в заново посчитанном списке: вариант доставки
 * (shopCheckoutShippingStep) или способ оплаты, отфильтрованный по доставке. Ни ошибки, ни
 * следа в ответе. Единственная улика — эхо-кэш: он пишется из POST этого же запроса раньше,
 * чем ядро выкинуло выбор, и поэтому помнит то, чего в ответе уже нет. На следующем запросе
 * секция пришлёт пустой выбор, эхо будет стёрто как «покупатель сам оставил пустым» — потому
 * и ловить надо в том запросе, где выбор пропал.
 *
 * Чистые функции «факты → решение»: порядок веток запирается таблицей истинности
 * (tests/LostChoiceDetectorTest.php), а не надеждой на то, что правка его не переставит.
 *
 * См. docs/todo/zen-lost-variant-silent-expand.md
 */
class shopPrefillPluginLostChoiceDetector
{
    public const KIND_DELIVERY = 'delivery';
    public const KIND_PAYMENT  = 'payment';

    /**
     * Потерян ли один выбор (вариант доставки либо способ оплаты — логика одна).
     *
     * @param array{echo_id: ?string, step_skipped: bool, selected_id: ?string, notified_id?: ?string} $facts
     *        echo_id      — выбор из эхо-кэша на момент рендера (null — эха нет);
     *        step_skipped — шаг не считался (fast_render, короткое замыкание, шаг выключен,
     *                       список способов не посчитан): пустой ответ не довод;
     *        selected_id  — выбор, который ядро оставило в ответе (для оплаты — id из эха,
     *                       если он есть среди посчитанных способов: `selected_method_id`
     *                       ядро отдаёт сырым значением из POST, ему верить нельзя);
     *        notified_id  — выбор, о потере которого покупателя уже предупредили.
     * @return array{lost: bool, reason: string}
     */
    public static function decide(array $facts): array
    {
        // Нечего терять: покупатель ничего не выбирал либо эхо сброшено (смена типа,
        // смена адреса, заказ создан). «Не выбрал» и «потерял» различает только эхо.
        if (($facts['echo_id'] ?? null) === null || $facts['echo_id'] === '') {
            return ['lost' => false, 'reason' => 'nothing_chosen'];
        }

        // Пустой ответ шага — не довод: при fast_render он пуст на каждой загрузке /order/
        if (!empty($facts['step_skipped'])) {
            return ['lost' => false, 'reason' => 'step_skipped'];
        }

        if (($facts['selected_id'] ?? null) === null || $facts['selected_id'] === '') {
            // Мёртвый выбор остаётся в сессии, и форма шлёт его заново на каждой загрузке
            // страницы: без этой ветки предупреждение повторялось бы до первого касания
            // покупателем формы, а оно должно быть разовым.
            if (($facts['notified_id'] ?? null) === $facts['echo_id']) {
                return ['lost' => false, 'reason' => 'already_notified'];
            }

            return ['lost' => true, 'reason' => 'missing_in_response'];
        }

        // Ядро оставило какой-то выбор (тот же или другой) — потери нет
        return ['lost' => false, 'reason' => 'kept'];
    }

    /**
     * Какой диалог показать, если их несколько просится сразу.
     *
     * Один запрос — не больше одного диалога. Доставка главнее оплаты: ядро фильтрует
     * способы оплаты по выбранной доставке, так что после выбора новой доставки оплата
     * пересчитается сама, и если она всё ещё недоступна — об этом скажет следующий рендер.
     *
     * @param array{lost: bool} $delivery Решение decide() по доставке
     * @param array{lost: bool} $payment  Решение decide() по оплате
     * @return string|null KIND_DELIVERY | KIND_PAYMENT | null (молчим)
     */
    public static function pickDialog(array $delivery, array $payment): ?string
    {
        if (!empty($delivery['lost'])) {
            return self::KIND_DELIVERY;
        }

        if (!empty($payment['lost'])) {
            return self::KIND_PAYMENT;
        }

        return null;
    }
}
