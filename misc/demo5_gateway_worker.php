<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit;
$path='/etc/tarasec/demo5-gateway.json';
$cfg=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
$parameter='/sys/module/tarakernel/parameters/demo5_pause_until';
// No persistent doTagging change: the kernel itself expires this test override.
if (empty($cfg['enabled'])) {if(is_writable($parameter)) file_put_contents($parameter,'0');exit;}
$base=rtrim((string)$cfg['db_url'],'/');$token=(string)$cfg['node_token'];
if (!filter_var($base,FILTER_VALIDATE_URL)||!in_array(parse_url($base,PHP_URL_SCHEME),['http','https'],true)||strlen($token)<32) throw new RuntimeException('Invalid gateway configuration');
$lock=fopen('/run/tarasec-demo5-gateway.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)) exit;
$url=$base.'/script/demo5GatewayControl.php';
$opt=['timeout'=>5,'follow_location'=>0,'ignore_errors'=>true,'header'=>"X-TaraSec-Node-Token: $token\r\n"];
$raw=@file_get_contents($url,false,stream_context_create(['http'=>$opt]));
if (!preg_match('/^HTTP\/\S+ 200\b/',($http_response_header??[])[0]??'')) throw new RuntimeException('Gateway control feed unavailable; kernel expiry remains in force');
$feed=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$rows=$feed['sessions']??[];
if (!$rows) {if(is_writable($parameter))file_put_contents($parameter,'0');exit;}
$r=$rows[0];$until=(int)$r['pause_until'];$now=time();$state='restored';$message='Kernel pause expired or released';
try {
    if (!is_writable($parameter)) throw new RuntimeException('Updated tarakernel with expiring pause parameter required');
    if ($until>$now+180) throw new RuntimeException('Pause exceeds bounded exercise');
    $value=$until>$now?$until:0;
    if(file_put_contents($parameter,(string)$value)===false) throw new RuntimeException('Kernel pause write failed');
    if((int)trim(file_get_contents($parameter))!==$value) throw new RuntimeException('Kernel pause verification failed');
    if($value) {$state='pause_configured';$message='Kernel pause configured until '.gmdate('c',$value).'; receiver evidence must verify untagged traffic';}
} catch(Throwable $e) {$state='error';$message=$e->getMessage();}
$opt['method']='POST';$opt['header'].="Content-Type: application/json\r\n";
$opt['content']=json_encode(['session_id'=>$r['session_id'],'state'=>$state,'message'=>$message]);
file_get_contents($url,false,stream_context_create(['http'=>$opt]));
if (!preg_match('/^HTTP\/\S+ 200\b/',($http_response_header??[])[0]??'')) throw new RuntimeException('Gateway pause acknowledgement failed');
