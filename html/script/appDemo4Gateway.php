<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once '../dbfunc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function demo4GatewayReply(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
    demo4GatewayReply(405, ['ok'=>false,'error'=>'post_required']);
$id = strtolower((string)($_POST['session_id'] ?? ''));
$token = strtolower((string)($_POST['token'] ?? ''));
$phase = (string)($_POST['phase'] ?? '');
$phone = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/D',$id) ||
    !preg_match('/^[a-f0-9]{64}$/D',$token) ||
    !in_array($phase,['clean','infected'],true) ||
    !filter_var($phone,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))
    demo4GatewayReply(400,['ok'=>false,'error'=>'invalid_demo4_report']);

try {
    $db = getConnection();
    $stmt = $db->prepare("SELECT CAST(active AS UNSIGNED) active,severity,why
        FROM internalInfections WHERE ip=INET_ATON(?)
        ORDER BY (active=b'1' AND severity>1) DESC,
            COALESCE(lastSeen,inserted) DESC,infectionId DESC LIMIT 1");
    $stmt->bind_param('s',$phone); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $infected = $row && (int)$row['active']===1 && (int)$row['severity']>1;
    $demoInfected = $infected && str_starts_with((string)$row['why'],'DEMO:');
    if (($phase==='clean' && $infected) || ($phase==='infected' && !$demoInfected))
        demo4GatewayReply(409,['ok'=>false,'error'=>'gateway_phone_state_mismatch']);
    $res = $db->query("SELECT INET_NTOA(globalDb1ip) address FROM setup LIMIT 1");
    $server = (string)($res->fetch_assoc()['address'] ?? '');
    $db->close();
    if (!filter_var($server,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))
        demo4GatewayReply(503,['ok'=>false,'error'=>'global_db_not_configured']);

    $url = 'http://'.$server.'/script/appDemo4Session.php?action=gateway';
    $form = http_build_query(['session_id'=>$id,'token'=>$token,'phase'=>$phase]);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$form,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>8,
            CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http'=>[
            'method'=>'POST',
            'header'=>"Content-Type: application/x-www-form-urlencoded\r\n",
            'content'=>$form,
            'timeout'=>8,
            'ignore_errors'=>true,
            'follow_location'=>0,
        ]]);
        $response = @file_get_contents($url,false,$context);
        $code = 0;
        foreach (($http_response_header ?? []) as $line)
            if (preg_match('~^HTTP/\\S+ (\\d{3})~',$line,$match)) $code=(int)$match[1];
    }
    $result = is_string($response) ? json_decode($response,true) : null;
    if ($code!==200 || !is_array($result) || ($result['ok'] ?? false)!==true)
        demo4GatewayReply(503,['ok'=>false,'error'=>'central_session_report_failed']);
    demo4GatewayReply(200,['ok'=>true,'phase'=>$phase,
        'gatewayConfirmed'=>true,'routeProven'=>false]);
} catch (Throwable $e) {
    error_log('appDemo4Gateway.php: '.$e->getMessage());
    demo4GatewayReply(503,['ok'=>false,'error'=>'gateway_report_unavailable']);
}
