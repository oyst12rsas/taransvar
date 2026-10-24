<?php
declare(strict_types=1);
// Run with php -n: deterministic HTTPS transport adapter, no network requests.
foreach (['CURLOPT_POST','CURLOPT_POSTFIELDS','CURLOPT_HTTPHEADER','CURLOPT_RETURNTRANSFER','CURLOPT_FOLLOWLOCATION','CURLOPT_PROXY','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_PROTOCOLS','CURLPROTO_HTTPS','CURLINFO_RESPONSE_CODE'] as $i=>$name) define($name,$i+1);
function curl_init($url) { if ($url!=='https://tarasec.org/ops/agent/activity.php?action=verify') throw new Exception('Wrong service'); return true; }
function curl_setopt_array($curl,$options) {
    if ($options[CURLOPT_FOLLOWLOCATION]!==false || $options[CURLOPT_PROXY]!=='' || $options[CURLOPT_SSL_VERIFYPEER]!==true || $options[CURLOPT_SSL_VERIFYHOST]!==2 || $options[CURLOPT_PROTOCOLS]!==CURLPROTO_HTTPS) throw new Exception('Unsafe transport');
}
function curl_exec($curl) { return $GLOBALS['reply']; }
function curl_getinfo($curl,$key) { return $GLOBALS['code']; }
function curl_close($curl) {}
require __DIR__.'/../html/script/operationsActivityAuth.php';
$ip='100.68.176.110'; $token=str_repeat('b',64);
$good=['ok'=>true,'scope'=>'operations_activity','source_ip'=>$ip];
foreach ([[200,$good,true],[403,$good,false],[503,$good,false],[200,['ok'=>true,'scope'=>'other','source_ip'=>$ip],false],[200,['ok'=>true,'scope'=>'operations_activity','source_ip'=>'100.68.1.1'],false],[200,['ok'=>false],false]] as [$code,$body,$expected]) {
    $GLOBALS['code']=$code; $GLOBALS['reply']=json_encode($body);
    if (operationsActivityRemoteAuth($token,$ip)!==$expected) throw new Exception('Authentication result mismatch');
}
if (operationsActivityRemoteAuth($token,'127.0.0.1') || operationsActivityRemoteAuth('bad',$ip)) throw new Exception('Invalid input accepted');
echo "Activity authentication checks passed\n";
