<?php
declare(strict_types=1);
require_once __DIR__.'/../html/script/sshClientSource.php';
foreach (['10.100.0.150','172.16.1.2','192.168.1.2','100.68.1.2','fd00::1234','::ffff:10.100.0.150'] as $ip) {
    if (queueConnectingSource($ip)==='') throw new RuntimeException('Private source rejected');
}
foreach (['203.0.113.2','8.8.8.8','127.0.0.1','fe80::1','10.100.0.150.evil','invalid'] as $ip) {
    $rejected=false;try { queueConnectingSource($ip); } catch (RuntimeException $e) { $rejected=true; }
    if (!$rejected) throw new RuntimeException('Unsupported source accepted');
}
$endpoint=__DIR__.'/../html/script/sshClientQueue.php';
foreach ([['GET','100.68.1.2','post_required'],['POST','203.0.113.2','private_source_required']] as [$method,$source,$error]) {
    $program='$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';$_SERVER["REMOTE_ADDR"]='.var_export($source,true).';$_SERVER["HTTP_X_FORWARDED_FOR"]="100.68.1.2";require '.var_export($endpoint,true).';';
    $process=proc_open([PHP_BINARY,'-r',$program],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $body=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $code=proc_close($process);$value=json_decode($body,true);
    if ($code!==0 || ($value['error']??'')!==$error) throw new RuntimeException('Bridge method/source validation failed: '.$stderr);
}
echo "PASS: gateway bridge rejects unsupported method and forged forwarding headers before credential access\n";

