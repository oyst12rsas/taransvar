<?php
declare(strict_types=1);
ini_set('display_errors','0');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once '../dbfunc.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
function gatewayReply(int $code,array $b): never {http_response_code($code);echo json_encode($b);exit;}
try {
    $db=getConnection();$ip=(string)($_SERVER['REMOTE_ADDR']??'');$token=(string)($_SERVER['HTTP_X_TARASEC_NODE_TOKEN']??'');
    $role=$db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) central FROM setup LIMIT 1')->fetch_assoc();
    if (empty($role['central'])) gatewayReply(409,['ok'=>false]);
    $q=$db->prepare('SELECT r.routerId,x.tokenHash FROM partnerRouter r JOIN partnerRestrictionReceiver x ON x.receiverIp=r.ip AND x.enabled=1 WHERE r.ip=INET_ATON(?) AND r.demo5Enabled=1');
    $q->bind_param('s',$ip);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
    if (!$r||strlen($token)<32||!hash_equals($r['tokenHash'],hash('sha256',$token))) gatewayReply(403,['ok'=>false]);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $b=json_decode(file_get_contents('php://input',false,null,0,4096),true,512,JSON_THROW_ON_ERROR);
        $id=(string)($b['session_id']??'');$state=(string)($b['state']??'');$message=substr((string)($b['message']??''),0,255);
        if (!in_array($state,['pause_configured','restored','error'],true)) gatewayReply(400,['ok'=>false]);
        $q=$db->prepare('UPDATE demo5Session SET pauseState=?,pauseMessage=?,pauseCheckedAt=NOW() WHERE sessionId=? AND routerId=?');
        $q->bind_param('sssi',$state,$message,$id,$r['routerId']);$q->execute();gatewayReply(200,['ok'=>true]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='GET') gatewayReply(405,['ok'=>false]);
    $q=$db->prepare("SELECT sessionId session_id,
        IF(state IN ('released','expired'),0,LEAST(UNIX_TIMESTAMP(expiresAt),UNIX_TIMESTAMP(created)+180)) pause_until
        FROM demo5Session WHERE routerId=? AND (expiresAt>NOW() OR pauseCheckedAt>DATE_SUB(NOW(),INTERVAL 10 MINUTE)) ORDER BY created DESC LIMIT 1");
    $q->bind_param('i',$r['routerId']);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);
    gatewayReply(200,['ok'=>true,'sessions'=>$rows]);
} catch(Throwable $e) {error_log('demo5GatewayControl: '.$e->getMessage());gatewayReply(503,['ok'=>false]);}
