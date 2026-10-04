<?php
/**
 * F-44: карта решений детектора потери выбора (P10) по видам ошибки выше по форме.
 *
 * Перед каждым сценарием гость делает здоровый запрос (курьер + оплата 20 → пишутся оба эхо),
 * затем тот же запрос с одной сломанной деталью. Ожидание после исправления 04.10.2026
 * (docs/bugs/done/lost-choice-false-positive-on-upstream-error.md): при ошибке auth/region
 * доставка — `step_skipped`, а не `missing_in_response`; контроль и ошибки details/confirm — `kept`.
 * Печатает «вид ошибки → решение по доставке и оплате» из лога плагина (нужен уровень debug).
 *
 * Лог: путь в переменной окружения WA_PLUGIN_LOG, по умолчанию лог стенда разработки.
 */
require 'guest-harness.php';
define('PLUGIN_LOG', getenv('WA_PLUGIN_LOG') ?: '/Users/artem/Project/wa-dev/wa-log/prefill.plugin.log');
$region=['region[country]'=>'rus','region[region]'=>'54','region[city]'=>'Новосибирск'];
$addr=['details[shipping_address][street]'=>'Красный проспект','details[shipping_address][building]'=>'1','details[shipping_address][apartment]'=>'2','details[shipping_address][zip]'=>'630000','details[shipping_address][podezd]'=>'1'];
$cour=['shipping[type_id]'=>'todoor','shipping[variant_id]'=>'33.courier'];
$ok=['auth[data][firstname]'=>'Тест','auth[data][phone]'=>'+7 923 111-22-33','auth[data][email]'=>'guest-test@example.com'];
$pay=['payment[id]'=>'20'];
$cases=[
 'здоровый (контроль)'                  => $region+$cour+$addr+$ok+$pay,
 'битая почта (auth)'                   => $region+$cour+$addr+['auth[data][email]'=>'not-an-email']+$pay,
 'почта админа (admin_error, auth)'     => $region+$cour+$addr+['auth[data][email]'=>'artem.shamberger@gmail.com']+$pay,
 'test@mail..ru (серверная, auth)'      => $region+$cour+$addr+['auth[data][email]'=>'test@mail..ru']+$pay,
 'пустой город (region)'                => ['region[country]'=>'rus','region[region]'=>'54','region[city]'=>'']+$cour+$addr+$ok+$pay,
 'не заполнен обязательный zip (details)'=> $region+$cour+['details[shipping_address][street]'=>'Красный']+$ok+$pay,
 'терминс не принят (confirm)'          => $region+$cour+$addr+$ok+$pay,
];
foreach($cases as $name=>$p){
  $g=new G; $g->start(); $g->req('/order/');
  $g->calc($region+$cour+$addr+$ok+$pay);                      // здоровый запрос → эхо
  $mark=count(file(PLUGIN_LOG));
  $d=$g->calc($p);
  $log=array_slice(file(PLUGIN_LOG),$mark);
  $txt=implode('',$log); preg_match_all('/"kind": "(\w+)",\s+"lost": (true|false),\s+"reason": "(\w+)"/',$txt,$m,PREG_SET_ORDER);
  $dec=array_map(fn($x)=>"{$x[1]}:{$x[2]}({$x[3]})",$m);
  printf("%-42s error_step=%-8s -> %s\n",$name,json_encode($d['error_step_id']??null),implode('  ',$dec));
}
