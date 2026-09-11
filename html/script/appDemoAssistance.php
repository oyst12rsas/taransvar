<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
include '../dbfunc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply3(int $status, array $data): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function body3(): array { $raw=file_get_contents('php://input'); $j=json_decode($raw ?: '', true); return is_array($j)?$j:$_POST; }
function token3(): string { return bin2hex(random_bytes(32)); }
function category3(int $sid): string { return 'demo3_'.$sid; }

function queueAssistance3(mysqli $c, int $sid, string $targetIp, int $threshold, bool $active): int {
    $category=category3($sid);
    $comment=($active?'DEMO3 start ':'DEMO3 release ').$sid;
    $activeInt=$active?1:0;
    $s=$c->prepare("INSERT INTO assistanceRequest (purpose,ip,port,category,comment,requestQuality,wantSpoofed,active) VALUES ('forDistribution',INET_ATON(?),0,?,?,?,b'0',?)");
    $s->bind_param('sssii',$targetIp,$category,$comment,$threshold,$activeInt);
    $s->execute(); $id=(int)$s->insert_id; $s->close(); return $id;
}

function advance3(mysqli $c, int $sid): void {
    $s=$c->prepare("SELECT state,targetIp,threshold,containmentSeconds,blockAt,releaseAt,assistanceRequestId,releaseRequestId FROM demoAssistanceSession WHERE sessionId=? FOR UPDATE");
    $s->bind_param('i',$sid); $s->execute(); $r=$s->get_result()->fetch_assoc(); $s->close();
    if(!$r) return;

    $now=time();
    if($r['state']==='active' && $r['blockAt'] && strtotime($r['blockAt'].' UTC') <= $now && empty($r['assistanceRequestId'])) {
        $rid=queueAssistance3($c,$sid,(string)$r['targetIp'],(int)$r['threshold'],true);
        $containment=max(15,(int)$r['containmentSeconds']);
        $s=$c->prepare("UPDATE demoAssistanceSession SET state='contained',assistanceRequestId=?,assistanceIssuedAt=UTC_TIMESTAMP(),releaseAt=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) WHERE sessionId=?");
        $s->bind_param('iii',$rid,$containment,$sid); $s->execute(); $s->close();
    }

    $s=$c->prepare("SELECT state,targetIp,threshold,releaseAt,releaseRequestId FROM demoAssistanceSession WHERE sessionId=?");
    $s->bind_param('i',$sid); $s->execute(); $r=$s->get_result()->fetch_assoc(); $s->close();
    if(!$r) return;

    if(($r['state']==='contained'||$r['state']==='releasing') && $r['releaseAt'] && strtotime($r['releaseAt'].' UTC') <= $now && empty($r['releaseRequestId'])) {
        $rid=queueAssistance3($c,$sid,(string)$r['targetIp'],(int)$r['threshold'],false);
        $s=$c->prepare("UPDATE demoAssistanceSession SET state='closed',releaseRequestId=?,closedAt=UTC_TIMESTAMP() WHERE sessionId=?");
        $s->bind_param('ii',$rid,$sid); $s->execute(); $s->close();
    }

    // A participant above the threshold is considered observably contained only
    // after its polling actually stops. This independently verifies enforcement.
    $s=$c->prepare("UPDATE demoAssistanceParticipant p JOIN demoAssistanceSession d ON d.sessionId=p.sessionId SET p.decision='silent',p.firstSilentAt=COALESCE(p.firstSilentAt,UTC_TIMESTAMP()) WHERE p.sessionId=? AND d.state IN ('contained','releasing') AND p.severity>d.threshold AND p.decision IN ('pending','connected') AND (p.lastSeenAt IS NULL OR p.lastSeenAt<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 SECOND))");
    $s->bind_param('i',$sid); $s->execute(); $s->close();
}

function session3(mysqli $c, int $sid): ?array {
    $c->begin_transaction();
    try { advance3($c,$sid); $c->commit(); } catch(Throwable $e){ $c->rollback(); throw $e; }
    $s=$c->prepare("SELECT sessionId,name,threshold,state,targetIp,containmentSeconds,assistanceRequestId,releaseRequestId,startsAt,blockAt,releaseAt,GREATEST(0,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),blockAt)) remaining,GREATEST(0,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),releaseAt)) releaseRemaining FROM demoAssistanceSession WHERE sessionId=?");
    $s->bind_param('i',$sid); $s->execute(); $r=$s->get_result()->fetch_assoc(); $s->close(); if(!$r) return null;
    $s=$c->prepare("SELECT participantId,nickname,observedIp,severity,decision,lastSeenAt,firstSilentAt,recoveredAt,GREATEST(0,TIMESTAMPDIFF(SECOND,lastSeenAt,UTC_TIMESTAMP())) secondsSinceSeen FROM demoAssistanceParticipant WHERE sessionId=? ORDER BY participantId");
    $s->bind_param('i',$sid); $s->execute(); $q=$s->get_result(); $p=[]; $silent=0; $connected=0; $recovered=0;
    while($x=$q->fetch_assoc()){
        if($x['decision']==='silent')$silent++; elseif($x['decision']==='recovered')$recovered++; elseif($x['decision']==='connected')$connected++;
        $p[]=['participant_id'=>(int)$x['participantId'],'nickname'=>(string)$x['nickname'],'observed_ip'=>(string)$x['observedIp'],'severity'=>(int)$x['severity'],'decision'=>(string)$x['decision'],'last_seen'=>(string)($x['lastSeenAt']??''),'seconds_since_seen'=>$x['secondsSinceSeen']===null?null:(int)$x['secondsSinceSeen']];
    }
    $s->close();
    return ['session_id'=>(int)$r['sessionId'],'name'=>(string)$r['name'],'threshold'=>(int)$r['threshold'],'state'=>(string)$r['state'],'target_ip'=>(string)$r['targetIp'],'block_at'=>(string)($r['blockAt']??''),'release_at'=>(string)($r['releaseAt']??''),'seconds_remaining'=>(int)$r['remaining'],'release_seconds_remaining'=>$r['releaseAt']? (int)$r['releaseRemaining']:0,'assistance_request_id'=>$r['assistanceRequestId']? (int)$r['assistanceRequestId']:null,'release_request_id'=>$r['releaseRequestId']? (int)$r['releaseRequestId']:null,'participants'=>$p,'summary'=>['participants'=>count($p),'connected'=>$connected,'silent'=>$silent,'recovered'=>$recovered]];
}

$a=strtolower(trim((string)($_REQUEST['action']??'list'))); $b=body3();
try {
    $c=getConnection(); $c->query("SET time_zone='+00:00'");
    if($a==='create'){
        $name=trim((string)($b['name']??'Community infection exercise')); $threshold=(int)($b['threshold']??7); $delay=(int)($b['delay_seconds']??120); $containment=(int)($b['containment_seconds']??120); $targetIp=trim((string)($b['target_ip']??''));
        if($name===''||mb_strlen($name)>120||$threshold<0||$threshold>10||$delay<15||$delay>300||$containment<15||$containment>600||!filter_var($targetIp,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) reply3(400,['ok'=>false,'error'=>'invalid_demo_settings']);
        $controller=token3(); $s=$c->prepare("INSERT INTO demoAssistanceSession(name,threshold,state,controllerToken,targetIp,containmentSeconds,startsAt,blockAt) VALUES(?,?,'active',?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))");
        $s->bind_param('sissii',$name,$threshold,$controller,$targetIp,$containment,$delay); $s->execute(); $sid=(int)$s->insert_id; $s->close();
        reply3(201,['ok'=>true,'controller_token'=>$controller,'session'=>session3($c,$sid)]);
    }
    if($a==='list'){
        $q=$c->query("SELECT sessionId FROM demoAssistanceSession WHERE state='active' AND blockAt>DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 SECOND) ORDER BY blockAt ASC,sessionId ASC LIMIT 50");
        $items=[]; while($r=$q->fetch_assoc()){ $x=session3($c,(int)$r['sessionId']); if($x&&$x['state']==='active'&&$x['seconds_remaining']>15)$items[]=$x; }
        reply3(200,['ok'=>true,'sessions'=>$items]);
    }
    $sid=(int)($b['session_id']??$_REQUEST['session_id']??0); if($sid<1) reply3(400,['ok'=>false,'error'=>'invalid_session']);
    if($a==='join'){
        $cur=session3($c,$sid); if(!$cur||$cur['state']!=='active'||$cur['seconds_remaining']<=15) reply3(409,['ok'=>false,'error'=>'session_not_joinable']);
        $nick=trim((string)($b['nickname']??'')); if(mb_strlen($nick)>80) reply3(400,['ok'=>false,'error'=>'invalid_nickname']);
        $pt=token3(); $ip=trim((string)($_SERVER['REMOTE_ADDR']??'')); $s=$c->prepare("INSERT INTO demoAssistanceParticipant(sessionId,participantToken,nickname,observedIp,lastSeenAt,decision) VALUES(?,?,?,?,UTC_TIMESTAMP(),'connected')");
        $s->bind_param('isss',$sid,$pt,$nick,$ip); $s->execute(); $pid=(int)$s->insert_id; $s->close(); reply3(201,['ok'=>true,'participant_id'=>$pid,'participant_token'=>$pt,'session'=>session3($c,$sid)]);
    }
    if($a==='severity'){
        $cur=session3($c,$sid); $pt=trim((string)($b['participant_token']??'')); $sev=(int)($b['severity']??-1); if(!preg_match('/^[a-f0-9]{64}$/',$pt)||$sev<0||$sev>10) reply3(400,['ok'=>false,'error'=>'invalid_update']);
        if(!$cur||$cur['state']!=='active') reply3(409,['ok'=>false,'error'=>'update_rejected']);
        $s=$c->prepare("UPDATE demoAssistanceParticipant SET severity=?,lastSeenAt=UTC_TIMESTAMP(),decision='connected' WHERE sessionId=? AND participantToken=?");
        $s->bind_param('iis',$sev,$sid,$pt); $s->execute(); $n=$s->affected_rows; $s->close(); if($n<1) reply3(409,['ok'=>false,'error'=>'update_rejected']); reply3(200,['ok'=>true,'session'=>session3($c,$sid)]);
    }
    if($a==='heartbeat'){
        $pt=trim((string)($b['participant_token']??'')); if(!preg_match('/^[a-f0-9]{64}$/',$pt)) reply3(400,['ok'=>false,'error'=>'invalid_participant']);
        $cur=session3($c,$sid); if(!$cur) reply3(404,['ok'=>false,'error'=>'session_not_found']);
        $s=$c->prepare("UPDATE demoAssistanceParticipant SET recoveredAt=CASE WHEN decision='silent' THEN UTC_TIMESTAMP() ELSE recoveredAt END,decision=CASE WHEN decision='silent' THEN 'recovered' ELSE 'connected' END,lastSeenAt=UTC_TIMESTAMP() WHERE sessionId=? AND participantToken=?");
        $s->bind_param('is',$sid,$pt); $s->execute(); $n=$s->affected_rows; $s->close(); if($n<1) reply3(404,['ok'=>false,'error'=>'participant_not_found']); reply3(200,['ok'=>true,'session'=>session3($c,$sid)]);
    }
    if($a==='status') reply3(200,['ok'=>true,'session'=>session3($c,$sid)]);
    reply3(400,['ok'=>false,'error'=>'invalid_action']);
} catch(Throwable $e){ error_log('appDemoAssistance.php: '.$e->getMessage()); reply3(503,['ok'=>false,'error'=>'demo_assistance_unavailable']); }
