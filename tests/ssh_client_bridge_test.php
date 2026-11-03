<?php
declare(strict_types=1);
$endpoint=__DIR__.'/../html/script/sshClientQueue.php';
foreach ([['GET','100.68.1.2','post_required'],['POST','203.0.113.2','netbird_source_required']] as [$method,$source,$error]) {
    $program='$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';$_SERVER["REMOTE_ADDR"]='.var_export($source,true).';$_SERVER["HTTP_X_FORWARDED_FOR"]="100.68.1.2";require '.var_export($endpoint,true).';';
    $process=proc_open([PHP_BINARY,'-r',$program],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $body=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $code=proc_close($process);$value=json_decode($body,true);
    if ($code!==0 || ($value['error']??'')!==$error) throw new RuntimeException('Bridge method/source validation failed: '.$stderr);
}
echo "PASS: gateway bridge rejects unsupported method and forged forwarding headers before credential access\n";
