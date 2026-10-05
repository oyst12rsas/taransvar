<?php
declare(strict_types=1);
ini_set('display_errors','0');mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once '../dbfunc.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try {
    $db=getConnection();$ip=(string)($_SERVER['REMOTE_ADDR']??'');
    $role=$db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) central FROM setup LIMIT 1')->fetch_assoc();
    if(empty($role['central'])) throw new RuntimeException('DB server required');
    $requested=trim((string)($_GET['gateway_ip']??''));
    if ($requested!=='' && !filter_var($requested,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) {
        http_response_code(400);echo json_encode(['ok'=>false,'error'=>'invalid_gateway_ip']);exit;
    }
    $lookup=$requested!==''?$requested:$ip;
    $match=$requested!==''?'r.ip=INET_ATON(?)':'(INET_ATON(?) & r.nettmask)=(r.ip & r.nettmask)';
    $q=$db->prepare("SELECT r.routerId,INET_NTOA(r.ip) gateway_ip,r.taggingState,r.taggingStateUpdated,
        r.restrictionUntil,r.restrictionReason,
        GREATEST(0,TIMESTAMPDIFF(SECOND,NOW(),r.restrictionUntil)) restriction_seconds_remaining,
        TIMESTAMPDIFF(SECOND,r.taggingStateUpdated,NOW()) age_seconds
        FROM partnerRouter r WHERE $match
        ORDER BY BIT_COUNT(r.nettmask) DESC LIMIT 1");
    $q->bind_param('s',$lookup);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
    $delivery=null;
    if($r) {
        $q=$db->prepare("SELECT COUNT(*) expected,SUM(d.state='applied' AND d.appliedAt>=DATE_SUB(NOW(),INTERVAL 20 SECOND)) applied
            FROM partnerRestrictionDelivery d JOIN demo5Session s ON s.sessionId=d.sessionId
            WHERE s.routerId=? AND s.restrictionUntil>NOW()");
        $q->bind_param('i',$r['routerId']);$q->execute();$delivery=$q->get_result()->fetch_assoc();$q->close();
    }
    echo json_encode(['ok'=>true,'checked_at'=>gmdate('c'),'observed_source_ip'=>$ip,
        'lookup_basis'=>$requested!==''?'selected_gateway':'db_observed_source',
        'requested_gateway_ip'=>$requested,'partner'=>$r,'distribution'=>$delivery],JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {http_response_code(503);error_log('appPartnerStatus: '.$e->getMessage());echo json_encode(['ok'=>false,'error'=>'partner_status_unavailable']);}
