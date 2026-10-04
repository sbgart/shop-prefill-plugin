<?php
/**
 * Гостевой харнесс для curl-сценариев Дзен-режима и детектора потери выбора (ФО-06, F-31…F-44).
 *
 * Чистая банка кук на каждый `new G`, `calc()` шлёт `POST /order/calculate/` с HTML всех секций,
 * `table()` печатает `data-blocked-by` / `data-has-errors` по секциям-носителям групп.
 * Заменяет ручной curl из docs/tests/issue-63-browser-test-runbook.md.
 *
 * Стенд: BASE — витрина, товар 847 / sku 2268 (торшер), курьер `33.courier`, оплата `20`, регион
 * Новосибирск. На другом стенде поправить константы и id в сценариях.
 *
 * Не для CI: нужен живой стенд. Запуск сценариев — из каталога скрипта: `php f44-upstream-error-map.php`.
 */
// Гостевой харнесс: чистая банка кук, calculate, таблица data-blocked-by по секциям-носителям.
const BASE = 'https://wa-dev.loc';
class G {
    public $jar;
    function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    function req($path, $post = null, &$hdr = null) {
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_SSL_VERIFYHOST=>0,
            CURLOPT_COOKIEJAR=>$this->jar, CURLOPT_COOKIEFILE=>$this->jar, CURLOPT_HEADER=>1]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, 1); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post); }
        $r = curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        $hdr = substr($r, 0, $hs); return substr($r, $hs);
    }
    function start() { $this->req('/'); $this->req('/cart/add/', ['product_id'=>847,'sku_id'=>2268,'quantity'=>1]); }
    function calc(array $p) {
        $p += ['auth[html]'=>1,'shipping[html]'=>1,'details[html]'=>1,'payment[html]'=>1,'confirm[html]'=>1];
        $b = $this->req('/order/calculate/', $p); $d = json_decode($b, true);
        return $d ? $d['data'] : ['__raw'=>substr($b,0,300)];
    }
}
function table($d) {
    if (isset($d['__raw'])) return 'RAW: '.$d['__raw'];
    $out = [];
    $out[] = 'error_step_id='.json_encode($d['error_step_id']??null).' errors='.json_encode(array_map(fn($e)=>$e['name'],$d['errors']??[]));
    foreach (['auth'=>'customer','details'=>'delivery','payment'=>'payment'] as $sec=>$grp) {
        $h = $d[$sec]['html'] ?? '';
        preg_match_all('/data-group="'.$grp.'"[^>]*/', $h, $m);
        $blocked = '-'; $has=[];
        foreach ($m[0] as $tag) { if (preg_match('/data-blocked-by="([^"]*)"/',$tag,$b)) $blocked=$b[1]; if (strpos($tag,'data-has-errors')!==false) $has[]='has-errors'; if (strpos($tag,'data-nothing-to-summarize')!==false) $has[]='nothing'; }
        // кнопка может нести атрибуты в другом порядке — ищем весь тег кнопки группы
        preg_match_all('/<[^>]*js-prefill-zen-toggle[^>]*>/', $h, $bt);
        foreach ($bt[0] as $tag) { if (strpos($tag,'data-group="'.$grp.'"')!==false) { if (preg_match('/data-blocked-by="([^"]*)"/',$tag,$b)) $blocked=$b[1]; if (strpos($tag,'data-has-errors')!==false) $has[]='has-errors'; if (strpos($tag,'data-nothing-to-summarize')!==false) $has[]='nothing'; } }
        $out[] = sprintf('  %-8s carrier=%-8s blocked-by=%-9s %s', $grp, $sec, $blocked, implode(',',array_unique($has)));
    }
    return implode("\n", $out);
}
