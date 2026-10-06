<?php
declare(strict_types=1);
ini_set('display_errors','0');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once '../dbfunc.php';
require_once __DIR__.'/partnerObservationLib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function demo5Reply(int $code,array $body): never { http_response_code($code); echo json_encode($body,JSON_UNESCAPED_SLASHES); exit; }
try {
    $db=getConnection();
    $central=$db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) central FROM setup LIMIT 1')->fetch_assoc();
    if (empty($central['central'])) demo5Reply(409,['ok'=>false,'error'=>'db_server_required']);
    $remote=(string)($_SERVER['REMOTE_ADDR']??'');
    $action=(string)($_GET['action']??'status');
    $input=json_decode(file_get_contents('php://input')?:'',true);
    if (!is_array($input)) $input=$_POST;
    if ($action==='create') {
        if ($_SERVER['REQUEST_METHOD']!=='POST') demo5Reply(405,['ok'=>false,'error'=>'post_required']);
        // Attribution uses the DB-observed connection, never a caller-selected IP.
        // NAT subnodes work; routed subnets use the most specific registered mask.
        $q=$db->prepare("SELECT r.routerId,r.demo5Enabled,INET_NTOA(r.ip) gatewayIp,
            INET_NTOA(c.receiverIp) receiverIp,c.receiverPort
            FROM partnerRouter r JOIN demo5Configuration c ON c.routerId=r.routerId
            WHERE (INET_ATON(?) & r.nettmask)=(r.ip & r.nettmask)
            AND c.receiverIp<>r.ip ORDER BY BIT_COUNT(r.nettmask) DESC LIMIT 1");
        $q->bind_param('s',$remote); $q->execute(); $cfg=$q->get_result()->fetch_assoc(); $q->close();
        if (!$cfg || empty($cfg['demo5Enabled'])) demo5Reply(403,['ok'=>false,'error'=>'demo5_gateway_not_enabled_or_wrong_route']);
        $receiver=$cfg['receiverIp'];
        $q=$db->prepare("SELECT 1 FROM partnerRestrictionReceiver x,setup z
            WHERE x.receiverIp=INET_ATON(?) AND x.enabled=1
            AND x.receiverIp<>COALESCE(z.adminIP,0)
            AND x.receiverIp NOT IN (2130706433,INET_ATON(?)) LIMIT 1");
        $q->bind_param('ss',$receiver,$remote);$q->execute();$enabledReceiver=$q->get_result()->fetch_row();$q->close();
        if (!$enabledReceiver) demo5Reply(409,['ok'=>false,'error'=>'independent_receiver_must_be_enrolled']);
        $db->begin_transaction();
        $router=(int)$cfg['routerId'];
        $q=$db->prepare('SELECT routerId FROM partnerRouter WHERE routerId=? FOR UPDATE');
        $q->bind_param('i',$router); $q->execute(); $q->get_result()->fetch_assoc(); $q->close();
        $q=$db->prepare('SELECT sessionId FROM demo5Session WHERE routerId=? AND expiresAt>NOW() AND state NOT IN (\'released\',\'expired\') LIMIT 1');
        $q->bind_param('i',$router); $q->execute(); $existing=$q->get_result()->fetch_assoc(); $q->close();
        if ($existing) { $db->rollback(); demo5Reply(409,['ok'=>false,'error'=>'gateway_exercise_already_active']); }
        require_once __DIR__.'/partnerObservationLib.php';
        $observationCfg=partnerObservationConfig();
        if (empty($observationCfg['enabled'])) { $db->rollback(); demo5Reply(409,['ok'=>false,'error'=>'partner_observation_must_be_enabled']); }
        $id=bin2hex(random_bytes(16)); $token=bin2hex(random_bytes(32)); $hash=hash('sha256',$token);
        $target=$cfg['receiverIp']; $port=(int)$cfg['receiverPort'];
        $q=$db->prepare("INSERT INTO demo5Session(sessionId,tokenHash,routerId,sourceIp,receiverIp,receiverPort,expiresAt)
            VALUES(?,?,?,INET_ATON(?),INET_ATON(?),?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))");
        $q->bind_param('ssissi',$id,$hash,$router,$remote,$target,$port); $q->execute(); $q->close();
        $q=$db->prepare("INSERT INTO partnerObservation(routerId,sourceIp,sessionId,expiresAt)
            VALUES(?,INET_ATON(?),?,DATE_ADD(NOW(),INTERVAL 180 SECOND))");
        $q->bind_param('iss',$router,$remote,$id);$q->execute();$q->close();$db->commit();
        demo5Reply(200,['ok'=>true,'session_id'=>$id,'token'=>$token,'source_ip'=>$remote,
            'gateway_ip'=>$cfg['gatewayIp'],'receiver_ip'=>$target,'receiver_port'=>$port]);
    }
    $id=(string)($_GET['session_id']??$input['session_id']??'');
    $token=(string)($_SERVER['HTTP_X_TARASEC_DEMO_TOKEN']??'');
    if (!preg_match('/^[a-f0-9]{32}$/D',$id)) demo5Reply(400,['ok'=>false,'error'=>'invalid_session']);
    $q=$db->prepare("SELECT s.*,INET_NTOA(s.sourceIp) source_ip,INET_NTOA(r.ip) gateway_ip,
        INET_NTOA(s.receiverIp) receiver_ip,r.taggingState,r.taggingStateUpdated,
        GREATEST(0,TIMESTAMPDIFF(SECOND,NOW(),s.expiresAt)) seconds_remaining,
        GREATEST(0,TIMESTAMPDIFF(SECOND,NOW(),s.restrictionUntil)) restriction_seconds_remaining,
        GREATEST(15,LEAST(120,c.graceSeconds)) graceSeconds FROM demo5Session s JOIN partnerRouter r ON r.routerId=s.routerId
        JOIN demo5Configuration c ON c.routerId=s.routerId WHERE sessionId=?");
    $q->bind_param('s',$id); $q->execute(); $s=$q->get_result()->fetch_assoc(); $q->close();
    if (!$s||!hash_equals($s['tokenHash'],hash('sha256',$token))) demo5Reply(403,['ok'=>false,'error'=>'invalid_session_token']);
    if ($action==='release') {
        if ($_SERVER['REQUEST_METHOD']!=='POST') demo5Reply(405,['ok'=>false,'error'=>'post_required']);
        $q=$db->prepare("UPDATE demo5Session SET restrictionUntil=LEAST(COALESCE(restrictionUntil,NOW()),NOW()),
            expiresAt=NOW(),state='released' WHERE sessionId=?");
        $q->bind_param('s',$id); $q->execute(); $q->close();
        $q=$db->prepare("UPDATE partnerObservation SET expiresAt=NOW() WHERE sessionId=?");
        $q->bind_param('s',$id);$q->execute();$q->close();
        $q=$db->prepare("UPDATE partnerRouter SET restrictionUntil=NOW() WHERE routerId=? AND restrictionUntil<=?");
        $q->bind_param('is',$s['routerId'],$s['restrictionUntil']); $q->execute(); $q->close();
        demo5Reply(200,['ok'=>true,'message'=>'Release issued; receivers must confirm removal.']);
    }
    if ($action!=='status') demo5Reply(400,['ok'=>false,'error'=>'unknown_action']);
    $q=$db->prepare("SELECT INET_NTOA(receiverIp) receiver_ip,IF(state='applied' AND appliedAt<DATE_SUB(NOW(),INTERVAL 20 SECOND),'stale',state) state,message,appliedAt,releasedAt
        FROM partnerRestrictionDelivery WHERE sessionId=? ORDER BY receiverIp");
    $q->bind_param('s',$id); $q->execute(); $deliveries=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
    $q=$db->prepare("SELECT reportId,sourcePort,observedTag,observedAt FROM partnerIncidentEvidence
        WHERE sessionId=? ORDER BY evidenceId DESC LIMIT 10");
    $q->bind_param('s',$id); $q->execute(); $evidence=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
    $q=$db->prepare('SELECT observationId,status,notifiedAt,expiresAt FROM partnerObservation WHERE sessionId=? ORDER BY observationId DESC LIMIT 1');
    $q->bind_param('s',$id);$q->execute();$observation=$q->get_result()->fetch_assoc();$q->close();
    $metrics=null;
    if ($observation) {
        $q=$db->prepare('SELECT * FROM partnerObservationSample WHERE observationId=? AND checkedAt>DATE_SUB(NOW(),INTERVAL 90 SECOND)');
        $q->bind_param('i',$observation['observationId']);$q->execute();
        $metrics=partnerObservationDecision($q->get_result()->fetch_all(MYSQLI_ASSOC),partnerObservationConfig());$q->close();
    }
    $q=$db->prepare('SELECT INET_NTOA(t.receiverIp) ip,t.receiverPort port FROM demo5ObservationTarget t
        JOIN partnerRestrictionReceiver r ON r.receiverIp=t.receiverIp AND r.enabled=1
        WHERE t.routerId=? AND t.receiverIp<>INET_ATON(?) LIMIT 4');
    $q->bind_param('is',$s['routerId'],$s['gateway_ip']);$q->execute();$targets=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    $active=!empty($s['restrictionUntil']) && (int)$s['restriction_seconds_remaining']>0;
    $applied=count(array_filter($deliveries,fn($d)=>$d['state']==='applied'));
    $state=(string)$s['state'];
    if (!$active && !empty($s['restrictedAt']) && $state!=='released') $state='restriction_expired';
    if ((int)$s['seconds_remaining']===0 && empty($s['restrictedAt']) && $state!=='released') $state='expired';
    demo5Reply(200,['ok'=>true,'demo'=>5,'checked_at'=>gmdate('c'),'session_id'=>$id,
        'test_targets'=>$targets,'observation'=>$observation,'observation_metrics'=>$metrics,
        'pause_state'=>$s['pauseState'],'pause_message'=>$s['pauseMessage'],'pause_checked_at'=>$s['pauseCheckedAt'],
        'pause_until_epoch'=>min(strtotime($s['expiresAt']),strtotime($s['created'])+180),
        'state'=>$state,'source_ip'=>$s['source_ip'],'gateway_ip'=>$s['gateway_ip'],
        'origin'=>'router_or_subnode_unknown','receiver_ip'=>$s['receiver_ip'],'receiver_port'=>(int)$s['receiverPort'],
        'first_report_id'=>$s['firstReportId'],'latest_report_id'=>$s['latestReportId'],'partner_notified_at'=>$s['notifiedAt'],
        'tagging_state'=>$s['taggingState'],'tagging_state_updated'=>$s['taggingStateUpdated'],
        'grace_seconds'=>(int)$s['graceSeconds'],'seconds_remaining'=>(int)$s['seconds_remaining'],
        'blacklist_active'=>$active,'restriction_until'=>$s['restrictionUntil'],
        'restriction_seconds_remaining'=>(int)$s['restriction_seconds_remaining'],
        'scope'=>$s['source_ip'].'/32','applied_receivers'=>$applied,'expected_receivers'=>count($deliveries),
        'distribution_state'=>$active?($deliveries && $applied===count($deliveries)?'applied':'pending'):'inactive',
        'deliveries'=>$deliveries,'evidence'=>$evidence,
        'reason'=>'Repeated untagged rejection after successful partner notification is required. Missing tag evidence is unknown, not zero.']);
} catch(Throwable $e) { error_log('appDemo5: '.$e->getMessage()); demo5Reply(503,['ok'=>false,'error'=>'demo5_unavailable_check_migration']); }

