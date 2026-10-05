<?php
declare(strict_types=1);
ini_set('display_errors','0');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once '../dbfunc.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function restrictionReply(int $code,array $body): never {http_response_code($code);echo json_encode($body,JSON_UNESCAPED_SLASHES);exit;}
try {
    $db=getConnection();
    $role=$db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) central FROM setup LIMIT 1')->fetch_assoc();
    if (empty($role['central'])) restrictionReply(409,['ok'=>false,'error'=>'db_server_required']);
    $ip=(string)($_SERVER['REMOTE_ADDR']??''); $token=(string)($_SERVER['HTTP_X_TARASEC_NODE_TOKEN']??'');
    $q=$db->prepare('SELECT tokenHash FROM partnerRestrictionReceiver WHERE receiverIp=INET_ATON(?) AND enabled=1');
    $q->bind_param('s',$ip);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
    if (!$r || strlen($token)<32 || !hash_equals($r['tokenHash'],hash('sha256',$token))) restrictionReply(403,['ok'=>false,'error'=>'registered_receiver_token_required']);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $b=json_decode(file_get_contents('php://input')?:'',true)??[];
        $id=(string)($b['session_id']??'');$state=(string)($b['state']??'');$msg=substr((string)($b['message']??''),0,255);
        if (!in_array($state,['applied','released','error'],true)) restrictionReply(400,['ok'=>false,'error'=>'invalid_state']);
        $q=$db->prepare("UPDATE partnerRestrictionDelivery d JOIN demo5Session s ON s.sessionId=d.sessionId
            SET d.state=?,d.message=?,d.appliedAt=IF(?='applied',NOW(),d.appliedAt),
            d.releasedAt=IF(?='released',NOW(),d.releasedAt)
            WHERE d.sessionId=? AND d.receiverIp=INET_ATON(?)
            AND (?<>'applied' OR (s.state='blacklisted' AND s.restrictionUntil>NOW()))
            AND (?<>'released' OR s.restrictionUntil<=NOW())");
        $q->bind_param('ssssssss',$state,$msg,$state,$state,$id,$ip,$state,$state);$q->execute();$changed=$q->affected_rows;$q->close();
        restrictionReply($changed?200:409,['ok'=>(bool)$changed]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='GET') restrictionReply(405,['ok'=>false,'error'=>'get_or_post_required']);
    // Include unacknowledged releases. Never imply local application from delivery.
    $q=$db->prepare("SELECT s.sessionId session_id,INET_NTOA(s.sourceIp) source_ip,
        s.restrictionUntil restriction_until,GREATEST(0,TIMESTAMPDIFF(SECOND,NOW(),s.restrictionUntil)) ttl,
        d.state FROM partnerRestrictionDelivery d JOIN demo5Session s ON s.sessionId=d.sessionId
        WHERE d.receiverIp=INET_ATON(?) AND (s.restrictionUntil>NOW() OR d.state<>'released') ORDER BY ttl DESC,s.created DESC LIMIT 1000");
    $q->bind_param('s',$ip);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    restrictionReply(200,['ok'=>true,'restrictions'=>$rows]);
} catch(Throwable $e) {error_log('partnerRestrictions: '.$e->getMessage());restrictionReply(503,['ok'=>false,'error'=>'restrictions_unavailable']);}
