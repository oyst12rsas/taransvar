<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
include '../dbfunc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply3(int $status, array $data): never { http_response_code($status); echo json_encode($data); exit; }
function body3(): array { $raw=file_get_contents('php://input'); $j=json_decode($raw ?: '', true); return is_array($j)?$j:$_POST; }
function token3(): string { return bin2hex(random_bytes(32)); }
function contain3(mysqli $c, int $sid): void {
    $s=$c->prepare("SELECT threshold,state,blockAt FROM demoAssistanceSession WHERE sessionId=?");
    $s->bind_param('i',$sid); $s->execute(); $r=$s->get_result()->fetch_assoc(); $s->close();
    if(!$r || $r['state']!=='active' || !$r['blockAt'] || strtotime($r['blockAt'].' UTC')>time()) return;
    $threshold=(int)$r['threshold'];
    $s=$c->prepare("UPDATE demoAssistanceParticipant SET decision=CASE WHEN severity>=? THEN 'blocked' ELSE 'allowed' END WHERE sessionId=?");
    $s->bind_param('ii',$threshold,$sid); $s->execute(); $s->close();
    $s=$c->prepare("UPDATE demoAssistanceSession SET state='contained' WHERE sessionId=?");
    $s->bind_param('i',$sid); $s->execute(); $s->close();
}
function session3(mysqli $c, int $sid): ?array {
    contain3($c,$sid);
    $s=$c->prepare("SELECT sessionId,name,threshold,state,startsAt,blockAt,GREATEST(0,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),blockAt)) remaining FROM demoAssistanceSession WHERE sessionId=?");
    $s->bind_param('i',$sid); $s->execute(); $r=$s->get_result()->fetch_assoc(); $s->close(); if(!$r) return null;
    $s=$c->prepare("SELECT participantId,nickname,observedIp,severity,decision FROM demoAssistanceParticipant WHERE sessionId=? ORDER BY participantId");
    $s->bind_param('i',$sid); $s->execute(); $q=$s->get_result(); $p=[]; $blocked=0; $allowed=0;
    while($x=$q->fetch_assoc()){ if($x['decision']==='blocked')$blocked++; if($x['decision']==='allowed')$allowed++; $p[]=['participant_id'=>(int)$x['participantId'],'nickname'=>(string)$x['nickname'],'observed_ip'=>(string)$x['observedIp'],'severity'=>(int)$x['severity'],'decision'=>(string)$x['decision']]; }
    $s->close();
    return ['session_id'=>(int)$r['sessionId'],'name'=>(string)$r['name'],'threshold'=>(int)$r['threshold'],'state'=>(string)$r['state'],'block_at'=>(string)($r['blockAt']??''),'seconds_remaining'=>(int)$r['remaining'],'participants'=>$p,'summary'=>['participants'=>count($p),'blocked'=>$blocked,'allowed'=>$allowed]];
}

$a=strtolower(trim((string)($_REQUEST['action']??'current'))); $b=body3();
try {
    $c=getConnection(); $c->query("SET time_zone='+00:00'");
    if($a==='create'){
        $name=trim((string)($b['name']??'Community infection exercise')); $threshold=(int)($b['threshold']??7); $delay=(int)($b['delay_seconds']??120);
        if($name===''||mb_strlen($name)>120||$threshold<0||$threshold>10||$delay<10||$delay>3600) reply3(400,['ok'=>false,'error'=>'invalid_demo_settings']);
        $controller=token3(); $s=$c->prepare("INSERT INTO demoAssistanceSession(name,threshold,state,controllerToken,startsAt,blockAt) VALUES(?,?,'active',?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))");
        $s->bind_param('sisi',$name,$threshold,$controller,$delay); $s->execute(); $sid=(int)$s->insert_id; $s->close();
        reply3(201,['ok'=>true,'controller_token'=>$controller,'session'=>session3($c,$sid)]);
    }
    if($a==='current'){
        $q=$c->query("SELECT sessionId FROM demoAssistanceSession WHERE state IN('active','contained') ORDER BY sessionId DESC LIMIT 1"); $r=$q->fetch_assoc();
        reply3(200,['ok'=>true,'session'=>$r?session3($c,(int)$r['sessionId']):null]);
    }
    $sid=(int)($b['session_id']??$_REQUEST['session_id']??0); if($sid<1) reply3(400,['ok'=>false,'error'=>'invalid_session']);
    if($a==='join'){
        $cur=session3($c,$sid); if(!$cur||$cur['state']!=='active') reply3(409,['ok'=>false,'error'=>'session_not_joinable']);
        $nick=trim((string)($b['nickname']??'')); if(mb_strlen($nick)>80) reply3(400,['ok'=>false,'error'=>'invalid_nickname']);
        $pt=token3(); $ip=trim((string)($_SERVER['REMOTE_ADDR']??'')); $s=$c->prepare("INSERT INTO demoAssistanceParticipant(sessionId,participantToken,nickname,observedIp) VALUES(?,?,?,?)");
        $s->bind_param('isss',$sid,$pt,$nick,$ip); $s->execute(); $pid=(int)$s->insert_id; $s->close(); reply3(201,['ok'=>true,'participant_id'=>$pid,'participant_token'=>$pt,'session'=>session3($c,$sid)]);
    }
    if($a==='severity'){
        contain3($c,$sid); $pt=trim((string)($b['participant_token']??'')); $sev=(int)($b['severity']??-1); if(!preg_match('/^[a-f0-9]{64}$/',$pt)||$sev<0||$sev>10) reply3(400,['ok'=>false,'error'=>'invalid_update']);
        $s=$c->prepare("UPDATE demoAssistanceParticipant p JOIN demoAssistanceSession d ON d.sessionId=p.sessionId SET p.severity=? WHERE p.sessionId=? AND p.participantToken=? AND d.state='active'");
        $s->bind_param('iis',$sev,$sid,$pt); $s->execute(); $n=$s->affected_rows; $s->close(); if($n<1) reply3(409,['ok'=>false,'error'=>'update_rejected']); reply3(200,['ok'=>true,'session'=>session3($c,$sid)]);
    }
    if($a==='status') reply3(200,['ok'=>true,'session'=>session3($c,$sid)]);
    reply3(400,['ok'=>false,'error'=>'invalid_action']);
} catch(Throwable $e){ error_log('appDemoAssistance.php: '.$e->getMessage()); reply3(503,['ok'=>false,'error'=>'demo_assistance_unavailable']); }
