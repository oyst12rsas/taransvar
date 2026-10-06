<?php
declare(strict_types=1);
ini_set('display_errors','0');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once '../dbfunc.php';
require_once __DIR__.'/partnerObservationLib.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
function observationReply(int $code,array $body): never {http_response_code($code);echo json_encode($body);exit;}
try {
    $db=getConnection();$cfg=partnerObservationConfig();
    $role=$db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) central FROM setup LIMIT 1')->fetch_assoc();
    if (empty($role['central'])) observationReply(409,['ok'=>false]);
    $peer=(string)($_SERVER['REMOTE_ADDR']??'');$token=(string)($_SERVER['HTTP_X_TARASEC_NODE_TOKEN']??'');
    $q=$db->prepare('SELECT tokenHash FROM partnerRestrictionReceiver WHERE receiverIp=INET_ATON(?) AND enabled=1');
    $q->bind_param('s',$peer);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
    if (!$r || strlen($token)<32 || !hash_equals($r['tokenHash'],hash('sha256',$token))) observationReply(403,['ok'=>false]);
    if (empty($cfg['enabled'])) observationReply(200,['ok'=>true,'observations'=>[],'disabled'=>true]);
    $grace=max(15,min(120,(int)($cfg['grace_seconds']??30)));
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $q=$db->prepare('SELECT observationId id,INET_NTOA(sourceIp) source_ip,
            UNIX_TIMESTAMP(notifiedAt)+? start_epoch,UNIX_TIMESTAMP(expiresAt) end_epoch
            FROM partnerObservation WHERE notifiedAt IS NOT NULL
            AND notifiedAt<DATE_SUB(NOW(),INTERVAL ? SECOND) AND expiresAt>NOW()
            AND sourceIp<>INET_ATON(?) ORDER BY created DESC LIMIT 20');
        $q->bind_param('iis',$grace,$grace,$peer);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);
        observationReply(200,['ok'=>true,'observations'=>$rows]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') observationReply(405,['ok'=>false]);
    $raw=file_get_contents('php://input',false,null,0,4097);
    if (strlen($raw)>4096) observationReply(413,['ok'=>false]);
    $b=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$id=$b['id']??null;
    if (!is_numeric($id)|| (int)$id<1) observationReply(400,['ok'=>false]);
    $values=[];
    foreach(['untagged','tagged','unknownTag','maliciousUntagged','policyDeniedUntagged'] as $key) {
        $v=$b[$key]??null;
        if (!is_int($v)||$v<0||$v>1000000) observationReply(400,['ok'=>false]);
        $values[]=$v;
    }
    if ($values[3]+$values[4]>$values[0]) observationReply(400,['ok'=>false]);
    $q=$db->prepare('INSERT INTO partnerObservationSample
        (observationId,receiverIp,untagged,tagged,unknownTag,maliciousUntagged,policyDeniedUntagged)
        SELECT observationId,INET_ATON(?),?,?,?,?,? FROM partnerObservation
        WHERE observationId=? AND expiresAt>NOW() AND sourceIp<>INET_ATON(?)
        AND notifiedAt<DATE_SUB(NOW(),INTERVAL ? SECOND)
        ON DUPLICATE KEY UPDATE untagged=VALUES(untagged),tagged=VALUES(tagged),
        unknownTag=VALUES(unknownTag),maliciousUntagged=VALUES(maliciousUntagged),
        policyDeniedUntagged=VALUES(policyDeniedUntagged),checkedAt=NOW()');
    $q->bind_param('siiiiiisi',$peer,$values[0],$values[1],$values[2],$values[3],$values[4],$id,$peer,$grace);
    $q->execute();observationReply(200,['ok'=>true]);
} catch(Throwable $e) {error_log('partnerObservation: '.$e->getMessage());observationReply(503,['ok'=>false]);}
