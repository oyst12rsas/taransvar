<?php
declare(strict_types=1);
ini_set('display_errors','0');
// The gateway attests the actual overlay source. Caller headers/addresses are ignored.
require_once __DIR__.'/managerSshApprovalTarget.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function clientQueueReply(int $code,array $body): never {
    http_response_code($code);echo json_encode($body,JSON_UNESCAPED_SLASHES);exit;
}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') clientQueueReply(405,['ok'=>false,'error'=>'post_required']);
$source=(string)($_SERVER['REMOTE_ADDR']??'');
if (!filter_var($source,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || (ip2long($source)&0xffc00000)!==ip2long('100.64.0.0'))
    clientQueueReply(403,['ok'=>false,'error'=>'netbird_source_required']);
try {
    $path='/etc/tarasec/gatekeeper-google.php';$info=@lstat($path);
    if (!$info || is_link($path) || ($info['mode']&0170000)!==0100000 || $info['uid']!==0 || ($info['mode']&0027)!==0)
        throw new RuntimeException('configuration unavailable');
    $cfg=require $path;$target=managerSshApprovalTargetRead();
    if (!is_array($cfg) || $target===null || ($cfg['agent_api']??'')!==$target['approval_url'].'api.php'
        || !preg_match('/\A[a-f0-9]{64}\z/D',(string)($cfg['shared_secret']??'')) || !is_string($cfg['client_id']??null))
        throw new RuntimeException('configuration unavailable');
    $ch=curl_init($target['approval_url'].'ssh-device.php?action=client_poll');$raw='';
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$cfg['shared_secret'],'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['client_id'=>$cfg['client_id'],'source_ip'=>$source]),
        CURLOPT_WRITEFUNCTION=>static function($c,string $chunk) use(&$raw): int {
            if (strlen($raw)+strlen($chunk)>16384) return 0;
            $raw.=$chunk;return strlen($chunk);
        }]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    $answer=json_decode($raw,true);
    if ($ok===false || $status!==200 || !is_array($answer) || ($answer['ok']??false)!==true)
        throw new RuntimeException('queue unavailable');
    $job=$answer['job']??null;
    if (is_array($job) && ($job['server_ref']??'')!==$target['server_ref']) throw new RuntimeException('wrong server');
    clientQueueReply(200,['ok'=>true,'job'=>$job,'approval_service'=>$target['approval_url'],'server_ref'=>$target['server_ref']]);
} catch (Throwable $e) {
    clientQueueReply(503,['ok'=>false,'error'=>'ssh_client_queue_unavailable']);
}
