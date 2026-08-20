<?php

/**
 * Экранирует данные сводки Zen Mode перед подстановкой в Smarty-шаблон.
 *
 * Зачем в данных, а не в шаблоне: `Smarty::$escape_html = false`, автоэкранирования нет,
 * а шаблон сводки редактирует магазин и хранит его в БД (`shop_prefill_settings`). `|escape`
 * в дефолтных шаблонах закрыл бы дыру только на свежей установке — всё, что магазин напишет
 * сам, осталось бы незащищённым. Единственная точка, через которую данные попадают в любой
 * шаблон, — shopPrefillPluginZenData::extractSummaryData(), поэтому экранируем там.
 * См. docs/codereview/done/issue-56-zen-summary-no-escaping.md, правило Z7.
 *
 * Исключения — поля с `is_html` в getAvailableFields(): HTML, который собрал сам плагин
 * (внутри уже экранирован), и контент администратора, который ядро тоже выводит сырым.
 */
class shopPrefillPluginZenSummaryEscaper
{
    /** @var array<string, true> Ключи верхнего уровня, которые по контракту содержат HTML */
    private array $html_fields;

    /**
     * @param string[] $html_fields Имена полей, которые остаются без экранирования
     */
    public function __construct(array $html_fields)
    {
        $this->html_fields = array_fill_keys($html_fields, true);
    }

    /**
     * @param array $data Набор переменных шаблона из extractSummaryData()/getSampleData()
     * @return array Тот же набор, где всё, кроме HTML-полей, экранировано
     */
    public function escape(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = isset($this->html_fields[$key]) ? $value : $this->escapeValue($value);
        }

        return $result;
    }

    /**
     * Ключи массивов экранируются наравне со значениями: кастомные поля выводятся циклом
     * `{$k}: {$v}`, и имя поля приходит из тех же данных, что и значение.
     *
     * Не-строковые скаляры (int/float/bool/null) отдаём как есть — приведение к строке
     * сломало бы `{if}`-условия и типы в шаблонах, а экранировать в них нечего.
     *
     * @param mixed $value
     * @return mixed
     */
    private function escapeValue($value)
    {
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        if (is_array($value)) {
            $escaped = [];
            foreach ($value as $key => $item) {
                $escaped_key = is_string($key) ? htmlspecialchars($key, ENT_QUOTES, 'UTF-8') : $key;
                $escaped[$escaped_key] = $this->escapeValue($item);
            }

            return $escaped;
        }

        return $value;
    }
}
