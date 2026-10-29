<?php
// Диагностика хостинга: откройте https://ваш-домен/steam-check.php. Секретов не показывает. После настройки файл можно удалить.
header('Content-Type: text/plain; charset=utf-8');
$C=@include __DIR__.'/config.php';$ok=function($b){return $b?'OK ':'ОШИБКА ';};
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https'||($_SERVER['SERVER_PORT']??0)==443;
echo "PHP ".PHP_VERSION."\n";
echo $ok(version_compare(PHP_VERSION,'7.3','>='))."PHP >= 7.3\n";
echo $ok(function_exists('curl_init')||ini_get('allow_url_fopen'))."curl или allow_url_fopen (нужно для проверки входа Steam)\n";
echo $ok(function_exists('mb_substr'))."mbstring\n";
echo $ok($https)."Сайт открыт по HTTPS (".($https?'да':'нет — выпустите SSL и включите редирект').")\n";
echo "Адрес: ".($https?'https':'http')."://".($_SERVER['HTTP_HOST']??'?')."\n";
@mkdir(__DIR__.'/data/users',0755,true);
echo $ok(is_writable(__DIR__.'/data')&&is_writable(__DIR__.'/data/users'))."Папка data/ доступна на запись\n";
echo $ok(is_file(__DIR__.'/data/defaults.json'))."data/defaults.json на месте\n";
session_start();echo $ok(session_id()!=='')."Сессии работают\n";
echo (is_array($C)&&!empty($C['steam_api_key'])?'OK ':'— ')."Ключ Steam API ".(is_array($C)&&!empty($C['steam_api_key'])?'задан':'не задан (ник будет «Steam 123456», вход работает)')."\n";
echo (is_array($C)&&!empty($C['admin_ids'])&&!empty($C['admin_pin'])?'OK ':'— ')."Админка ".(is_array($C)&&!empty($C['admin_ids'])&&!empty($C['admin_pin'])?'настроена':'закрыта: заполните admin_ids и admin_pin в config.php')."\n";
$t=microtime(true);$r=false;
if(function_exists('curl_init')){$c=curl_init('https://steamcommunity.com/openid/');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=8,CURLOPT_NOBODY=>false]);$r=curl_exec($c);$e=curl_error($c);curl_close($c);}
else $r=@file_get_contents('https://steamcommunity.com/openid/',false,stream_context_create(['http'=>['timeout'=>8]]));
echo $ok($r!==false)."Связь сервера со Steam".($r===false?' — НЕТ: '.($e??'заблокировано'):' ('.round((microtime(true)-$t)*1000).' мс)')."\n";
