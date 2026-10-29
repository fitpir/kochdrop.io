<?php
// Koch Drop: серверная часть. Все расчёты (дроп, шансы, баланс, апгрейд, краш) выполняются здесь.
error_reporting(0);
$C=(function(){$c=@include __DIR__.'/config.php';return is_array($c)?$c:[];})();
const DD=__DIR__.'/data/';
@mkdir(DD.'users',0755,true);
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https'||strtolower($_SERVER['HTTP_X_FORWARDED_SSL']??'')==='on'||($_SERVER['SERVER_PORT']??0)==443||strtolower($_SERVER['HTTP_X_FORWARDED_SCHEME']??'')==='https';
$host=preg_replace('~[^a-z0-9.\-:]~i','',$_SERVER['HTTP_HOST']??'');
$base=!empty($C['base_url'])?rtrim($C['base_url'],'/'):($https?'https':'http').'://'.$host;
session_name('kd');session_set_cookie_params(['lifetime'=>2592000,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);session_start();

function out($a){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($a,JSON_UNESCAPED_UNICODE);exit;}
function err($m){out(['ok'=>false,'err'=>$m]);}
function rw($f,$fn){$h=@fopen($f,'c+');if(!$h)err('Нет прав на запись в папку data/ (поставьте 755/775)');flock($h,LOCK_EX);$s=stream_get_contents($h);$d=$s?json_decode($s,true):null;$r=$fn($d);ftruncate($h,0);rewind($h);fwrite($h,json_encode($d,JSON_UNESCAPED_UNICODE));fflush($h);flock($h,LOCK_UN);fclose($h);return $r;}
function cat($fresh=false){static $c;if($c&&!$fresh)return $c;$f=DD.'config.json';if(!is_file($f))copy(DD.'defaults.json',$f);return $c=json_decode(file_get_contents($f),true);}
function ufile($id){return DD.'users/'.preg_replace('~\W~','',$id).'.json';}
function newU($id,$cat){return ['id'=>$id,'name'=>'Гость','avatar'=>'','steam'=>0,'balance'=>(int)$cat['cfg']['start'],'opens'=>0,'rare'=>0,'inv'=>[],'hist'=>[],'uid'=>1,'st'=>['spent'=>0,'sold'=>0,'rounds'=>0,'crashBet'=>0,'crashWin'=>0],'usedP'=>[],'crh'=>[],'luck'=>0,'upb'=>0,'cr'=>null,'seen'=>time()];}
function withU($fn,$id=null){$id=$id??$_SESSION['u'];$cat=cat();return rw(ufile($id),function(&$u)use($fn,$id,$cat){if(!$u)$u=newU($id,$cat);$u['seen']=time();return $fn($u,$cat);});}
function lg(&$u,$t,$a=0){array_unshift($u['hist'],['t'=>time()*1000,'text'=>$t,'amt'=>$a]);$u['hist']=array_slice($u['hist'],0,50);}
function alog($t){global $C;$who=$_SESSION['sid']??'?';rw(DD.'log.json',function(&$d)use($t,$who){$d=$d?:[];array_unshift($d,['t'=>time()*1000,'who'=>$who,'text'=>$t]);$d=array_slice($d,0,200);});}
function skin($cat,$id){foreach($cat['skins'] as $k)if($k['id']==$id)return $k;return null;}
function ex($cat,$e){$k=skin($cat,$e['s']);if(!$k)return null;$r=$cat['rar'][$k['r']];return ['id'=>$e['id'],'sid'=>$k['id'],'name'=>$k['name'],'icon'=>$k['e']?:'🔫','value'=>(int)$k['p'],'rarity'=>$r['name'],'color'=>$r['color'],'img'=>$k['img']];}
function isAdm(){global $C;return !empty($_SESSION['adm'])&&in_array((string)($_SESSION['sid']??''),array_map('strval',$C['admin_ids']??[]),true);}
function pub($cat){$cs=[];foreach($cat['cases'] as $c)if(!empty($c['on']))$cs[]=$c;return ['rar'=>$cat['rar'],'skins'=>$cat['skins'],'cases'=>$cs];}
function stt($u,$cat){$inv=[];foreach($u['inv'] as $e)if($x=ex($cat,$e))$inv[]=$x;
 return ['balance'=>$u['balance'],'opens'=>$u['opens'],'rareDrops'=>$u['rare'],'inv'=>$inv,'hist'=>$u['hist'],'st'=>$u['st'],'crh'=>$u['crh'],'luck'=>$u['luck'],'upb'=>$u['upb'],
 'acc'=>['id'=>$u['id'],'name'=>$u['name'],'avatar'=>$u['avatar'],'steam'=>$u['steam'],'verified'=>true],'admin'=>isAdm(),'cat'=>pub($cat),'cfg'=>array_intersect_key($cat['cfg'],array_flip(['edge','minBet','maxBet','topup']))];}
function rnd(){return random_int(0,999999999)/1000000000;}
function pickSkin($cs,$cat,$luck){
 $w=$cs['odds']?:array_column($cat['rar'],'w');$m=1+max(-100,min(1000,$luck))/100;foreach($w as $i=>$x)if($i>=2)$w[$i]=$x*$m;
 $r=rnd()*array_sum($w);$t=0;foreach($w as $i=>$x){$r-=$x;if($r<0){$t=$i;break;}$t=$i;}
 $pool=function($k)use($cs,$cat){$o=[];foreach($cs['skins'] as $id)if(($s=skin($cat,$id))&&$s['r']==$k)$o[]=$s;return $o;};
 for($k=$t;$k>=0;$k--)if($p=$pool($k))return $p[random_int(0,count($p)-1)];
 for($k=$t+1;$k<5;$k++)if($p=$pool($k))return $p[random_int(0,count($p)-1)];
 err('В кейсе нет скинов');}
function crEval(&$u,$cash){$c=$u['cr'];if(!$c)return null;$m=exp(.15*(microtime(true)-$c['t0']));$pt=$c['pt'];$au=$c['auto'];$mm=$pt;
 if($au>=1.01&&$au<$pt&&$m>=$au){$won=true;$mm=$au;}elseif($m>=$pt){$won=false;}elseif($cash){$won=true;$mm=$m;}else return null;
 $u['cr']=null;$u['st']['rounds']++;array_unshift($u['crh'],$pt);$u['crh']=array_slice($u['crh'],0,12);$w=0;
 if($won){$w=(int)floor($c['bet']*$mm);$u['balance']+=$w;$u['st']['crashWin']+=$w;lg($u,'Краш: забрано на '.number_format($mm,2).'×',$w-$c['bet']);}else lg($u,'Краш: проигрыш (краш на '.number_format($pt,2).'×)',-$c['bet']);
 return ['won'=>$won,'win'=>$w,'m'=>round($mm,2),'pt'=>$pt,'bet'=>$c['bet']];}

/* ---------- вход через Steam (OpenID 2.0) ---------- */
$a=$_GET['a']??'';
function post($url,$body){if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=12,CURLOPT_CONNECTTIMEOUT=8,CURLOPT_USERAGENT=>'KochDrop/2']);$r=curl_exec($ch);curl_close($ch);if($r!==false)return $r;}
 return @file_get_contents($url,false,stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>$body,'timeout'=>12]]));}
function get($u){if(function_exists('curl_init')){$c=curl_init($u);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=10,CURLOPT_USERAGENT=>'KochDrop/2']);$r=curl_exec($c);curl_close($c);if($r!==false)return $r;}return @file_get_contents($u);}
if($a==='steam'){
 $q=http_build_query(['openid.ns'=>'http://specs.openid.net/auth/2.0','openid.mode'=>'checkid_setup','openid.return_to'=>$base.'/api.php?a=steam_return','openid.realm'=>$base.'/','openid.identity'=>'http://specs.openid.net/auth/2.0/identifier_select','openid.claimed_id'=>'http://specs.openid.net/auth/2.0/identifier_select']);
 header('Location: https://steamcommunity.com/openid/login?'.$q);exit;}
if($a==='steam_return'){
 $fail=function($r){header('Location: /?steam=fail&r='.$r);exit;};
 $p=[];foreach(explode('&',$_SERVER['QUERY_STRING']??'') as $pr){if($pr==='')continue;$kv=explode('=',$pr,2);$p[urldecode($kv[0])]=urldecode($kv[1]??'');}
 if(($p['openid.mode']??'')!=='id_res')$fail('cancel');
 if(!preg_match('~^https?://steamcommunity\.com/openid/id/(\d{17})$~',$p['openid.claimed_id']??'',$m))$fail('id');
 $rt=parse_url($p['openid.return_to']??'');$nw=function($h){return preg_replace('~^www\.~','',strtolower(preg_replace('~:\d+$~','',$h)));};
 if(!$rt||$nw($rt['host']??'')!==$nw($host)||substr($rt['path']??'',-7)!=='api.php')$fail('host');
 $p['openid.mode']='check_authentication';$res=post('https://steamcommunity.com/openid/login',http_build_query($p));
 if($res===false||$res==='')$fail('net');if(!preg_match('~is_valid\s*:\s*true~',$res))$fail('verify');
 $sid=$m[1];$name='Steam '.substr($sid,-6);$av='';
 if(!empty($C['steam_api_key'])){$j=json_decode(get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key='.urlencode($C['steam_api_key']).'&steamids='.$sid),true);$pl=$j['response']['players'][0]??null;if($pl){$name=$pl['personaname']??$name;$av=$pl['avatarfull']??'';}}
 session_regenerate_id(true);$_SESSION['u']='s'.$sid;$_SESSION['sid']=$sid;unset($_SESSION['adm']);
 withU(function(&$u)use($sid,$name,$av){$u['steam']=1;$u['name']=mb_substr($name,0,40);$u['avatar']=preg_match('~^https://~',$av)?$av:'';},'s'.$sid);
 header('Location: /?steam=ok');exit;}

/* ---------- JSON API ---------- */
if($_SERVER['REQUEST_METHOD']!=='POST'||empty($_SERVER['HTTP_X_KD']))err('bad request');
$in=json_decode(file_get_contents('php://input'),true)?:[];$act=$in['a']??'';
if(empty($_SESSION['u']))$_SESSION['u']='g'.bin2hex(random_bytes(8));
$ST=function()use($in){return withU(function(&$u,$cat){return stt($u,$cat);});};

switch($act){
case 'me': out(['ok'=>true,'state'=>$ST()]);
case 'logout': session_destroy();session_start();session_regenerate_id(true);$_SESSION['u']='g'.bin2hex(random_bytes(8));out(['ok'=>true,'state'=>$ST()]);
case 'open':
 $n=max(1,min(5,(int)($in['qty']??1)));$cid=(int)($in['case']??0);
 $r=withU(function(&$u,$cat)use($n,$cid){$cs=null;foreach($cat['cases'] as $c)if($c['id']==$cid&&!empty($c['on']))$cs=$c;if(!$cs)err('Кейс недоступен');
  $cost=$cs['price']*$n;if($u['balance']<$cost)err('Недостаточно виртуального баланса');if(count($u['inv'])>1500)err('Инвентарь переполнен — продай предметы');
  $u['balance']-=$cost;$u['st']['spent']+=$cost;$u['opens']+=$n;$d=[];
  for($i=0;$i<$n;$i++){$k=pickSkin($cs,$cat,$u['luck']);$e=['id'=>$u['uid']++,'s'=>$k['id']];$u['inv'][]=$e;if($k['r']>=3)$u['rare']++;$d[]=ex($cat,$e);}
  lg($u,'Кейс «'.$cs['name'].'» ×'.$n.': '.implode(', ',array_column($d,'name')),-$cost);return [$d,stt($u,$cat)];});
 out(['ok'=>true,'drops'=>$r[0],'state'=>$r[1]]);
case 'sell':
 $r=withU(function(&$u,$cat)use($in){$got=0;$keep=[];foreach($u['inv'] as $e){$x=ex($cat,$e);if(!empty($in['all'])||$e['id']==(int)($in['id']??-1)){$got+=$x?$x['value']:0;if($x)lg($u,'Продано: '.$x['name'],$x['value']);}else $keep[]=$e;}
  if(!empty($in['all']))lg($u,'Продано предметов: '.(count($u['inv'])-count($keep)),0);$u['inv']=$keep;$u['balance']+=$got;$u['st']['sold']+=$got;return [$got,stt($u,$cat)];});
 out(['ok'=>true,'got'=>$r[0],'state'=>$r[1]]);
case 'upgrade':
 $r=withU(function(&$u,$cat)use($in){if($u['cr'])err('Сначала заверши раунд краша');$si=null;foreach($u['inv'] as $i=>$e)if($e['id']==(int)($in['src']??-1))$si=$i;if($si===null)err('Предмет не найден');
  $s=ex($cat,$u['inv'][$si]);$t=skin($cat,(int)($in['tgt']??-1));if(!$s||!$t||$t['p']<=$s['value'])err('Неверная цель');
  $base=$s['value']/$t['p']*95;if($base<1)err('Слишком маленький шанс');$ch=min(90,$base*(1+$u['upb']/100));$roll=rnd()*100;$win=$roll<$ch;
  array_splice($u['inv'],$si,1);if($win)$u['inv'][]=['id'=>$u['uid']++,'s'=>$t['id']];
  lg($u,'Апгрейд '.($win?'удался':'не удался').': '.$s['name'].' → '.$t['name'].' ('.number_format($ch,2).'%)');return [$win,$roll,stt($u,$cat)];});
 out(['ok'=>true,'win'=>$r[0],'roll'=>$r[1],'state'=>$r[2]]);
case 'topup':
 $n=(int)floor($in['n']??0);
 $s=withU(function(&$u,$cat)use($n){$mx=(int)$cat['cfg']['topupMax'];if(empty($cat['cfg']['topup']))err('Пополнение отключено');if($n<1||$n>$mx)err('Сумма: от 1 до '.$mx.' (виртуальных ₽)');$u['balance']+=$n;lg($u,'Пополнение (демо)',$n);return stt($u,$cat);});
 out(['ok'=>true,'state'=>$s]);
case 'promo':
 $code=strtoupper(trim($in['code']??''));if($code==='')err('Введи промокод');
 $r=withU(function(&$u,$cat)use($code){$f=DD.'config.json';$amt=rw($f,function(&$c)use($code,$u){foreach($c['promos'] as &$p)if($p['code']===$code){if(in_array($code,$u['usedP']))err('Ты уже использовал этот промокод');if($p['max']&&$p['used']>=$p['max'])err('Лимит активаций исчерпан');$p['used']++;return $p['amount'];}err('Промокод не найден');});
  $u['usedP'][]=$code;$u['balance']+=$amt;lg($u,'Промокод '.$code,$amt);return [$amt,stt($u,cat(true))];});
 out(['ok'=>true,'got'=>$r[0],'state'=>$r[1]]);
case 'crash_bet':
 $b=(int)floor($in['bet']??0);$au=(float)($in['auto']??0);
 $s=withU(function(&$u,$cat)use($b,$au){$c=$cat['cfg'];crEval($u,false);if($u['cr'])err('Раунд уже идёт');if($b<$c['minBet']||$b>$c['maxBet'])err('Ставка: '.$c['minBet'].'–'.$c['maxBet']);if($b>$u['balance'])err('Недостаточно баланса');
  $u['balance']-=$b;$u['st']['crashBet']+=$b;$pt=min(1000,max(1,floor(100*(1-$c['edge']/100)/(1-rnd()))/100));$u['cr']=['bet'=>$b,'pt'=>$pt,'auto'=>$au>=1.01?$au:0,'t0'=>microtime(true)];return stt($u,$cat);});
 out(['ok'=>true,'state'=>$s]);
case 'crash_state': case 'crash_cash':
 $r=withU(function(&$u,$cat)use($act){$x=crEval($u,$act==='crash_cash');return [$x,stt($u,$cat)];});
 out(['ok'=>true,'res'=>$r[0],'state'=>$r[1]]+($r[0]?$r[0]:[]));
case 'adm_login':
 $pin=(string)($C['admin_pin']??'');if(!in_array((string)($_SESSION['sid']??''),array_map('strval',$C['admin_ids']??[]),true))err('Нет доступа: войди через Steam с ID админа (admin_ids в config.php)');
 if($pin===''||!hash_equals($pin,(string)($in['pin']??''))){sleep(1);err('Неверный PIN');}$_SESSION['adm']=1;out(['ok'=>true,'state'=>$ST()]);
}
if(!isAdm())err('Нет доступа');
$clean=function($s,$n=60){return mb_substr(trim(preg_replace('~[<>"\'`\\\\&]~u','',(string)$s)),0,$n);};
switch($act){
case 'adm_data':
 $us=[];foreach(glob(DD.'users/*.json') as $f){$u=json_decode(file_get_contents($f),true);if($u)$us[]=['id'=>$u['id'],'name'=>$u['name'],'steam'=>$u['steam'],'balance'=>$u['balance'],'luck'=>$u['luck'],'upb'=>$u['upb'],'inv'=>count($u['inv']),'opens'=>$u['opens'],'seen'=>$u['seen']];}
 usort($us,function($x,$y){return $y['seen']<=>$x['seen'];});
 out(['ok'=>true,'users'=>array_slice($us,0,300),'cat'=>cat(true),'logs'=>array_slice(json_decode(@file_get_contents(DD.'log.json'),true)?:[],0,40)]);
case 'adm_user':
 $id=preg_replace('~\W~','',$in['id']??'');if(!is_file(ufile($id)))err('Игрок не найден');
 if(!empty($in['del'])){unlink(ufile($id));alog('Удалён игрок '.$id);out(['ok'=>true]);}
 withU(function(&$u,$cat)use($in,$id){if(isset($in['balance']))$u['balance']=max(0,min(1000000000,(int)$in['balance']));if(isset($in['luck']))$u['luck']=max(-100,min(1000,(float)$in['luck']));if(isset($in['upb']))$u['upb']=max(-100,min(500,(float)$in['upb']));
  if(!empty($in['give'])&&skin($cat,(int)$in['give'])){$u['inv'][]=['id'=>$u['uid']++,'s'=>(int)$in['give']];lg($u,'Выдан предмет админом: '.skin($cat,(int)$in['give'])['name']);}return 1;},$id);
 alog('Игрок '.$id.': баланс='.($in['balance']??'-').' удача='.($in['luck']??'-').'% апгрейд='.($in['upb']??'-').'%'.(!empty($in['give'])?' выдан скин #'.(int)$in['give']:''));out(['ok'=>true]);
case 'adm_cat':
 $n=$in['cat']??[];$old=cat(true);$skins=[];$ids=[];$mx=0;
 foreach($n['skins']??[] as $k){$id=(int)($k['id']??0);if($id<0||isset($ids[$id]))continue;$ids[$id]=1;$mx=max($mx,$id);$img=trim($k['img']??'');if($img!==''&&!preg_match('~^https://[^\s"\'<>]+$~',$img))$img='';
  $skins[]=['id'=>$id,'name'=>$clean($k['name'],80)?:'Скин','r'=>max(0,min(4,(int)$k['r'])),'p'=>max(1,min(1000000000,(int)$k['p'])),'e'=>$clean($k['e']??'',8),'img'=>$img];}
 $cases=[];$cid=[];foreach($n['cases']??[] as $c){$id=(int)($c['id']??0);if($id<1||isset($cid[$id]))continue;$cid[$id]=1;$od=null;
  if(!empty($c['odds'])&&is_array($c['odds'])&&count($c['odds'])==5){$od=array_map(function($x){return max(0,(float)$x);},$c['odds']);if(array_sum($od)<=0)$od=null;}
  $img=trim($c['img']??'');if($img!==''&&!preg_match('~^https://[^\s"\'<>]+$~',$img))$img='';
  $cases[]=['id'=>$id,'name'=>$clean($c['name'])?:'Кейс','desc'=>$clean($c['desc'],120),'price'=>max(1,min(1000000000,(int)$c['price'])),'icon'=>$clean($c['icon']??'📦',8),'weapon'=>$clean($c['weapon']??'🔫',8),'glow'=>preg_match('~^#[0-9a-f]{6}$~i',$c['glow']??'')?$c['glow']:'#c7ff4a','tag'=>$clean($c['tag']??'',16),'real'=>$clean($c['real']??'',60),'img'=>$img,'on'=>!empty($c['on']),'odds'=>$od,'skins'=>array_values(array_filter(array_map('intval',$c['skins']??[]),function($x)use($ids){return isset($ids[$x]);}))];}
 $rar=$old['rar'];foreach($rar as $i=>&$r)if(isset($n['rar'][$i]['w']))$r['w']=max(0,(float)$n['rar'][$i]['w']);unset($r);if(array_sum(array_column($rar,'w'))<=0)err('Сумма шансов должна быть > 0');
 $pr=[];foreach($n['promos']??[] as $p){$cd=strtoupper($p['code']??'');if(preg_match('~^[A-Z0-9_-]{3,20}$~',$cd)&&(int)$p['amount']>0)$pr[]=['code'=>$cd,'amount'=>min(10000000,(int)$p['amount']),'max'=>max(0,(int)$p['max']),'used'=>max(0,(int)$p['used'])];}
 $g=$n['cfg']??[];$cfg=['edge'=>max(0,min(20,(float)($g['edge']??5))),'minBet'=>max(1,(int)($g['minBet']??10)),'maxBet'=>max(1,(int)($g['maxBet']??100000)),'topup'=>!empty($g['topup']),'topupMax'=>max(1,(int)($g['topupMax']??100000)),'start'=>max(0,(int)($g['start']??2500))];
 if(!$skins||!$cases)err('Нужен хотя бы один скин и один кейс');
 $new=['cfg'=>$cfg,'rar'=>$rar,'skins'=>$skins,'cases'=>$cases,'promos'=>$pr];rw(DD.'config.json',function(&$c)use($new){$c=$new;});alog('Каталог обновлён: скинов '.count($skins).', кейсов '.count($cases));
out(['ok'=>true,'cat'=>cat(true),'state'=>withU(function(&$u,$cat){return stt($u,cat(true));})]);
}
err('unknown');
