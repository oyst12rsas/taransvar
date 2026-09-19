<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../dbfunc.php';
require_once '../taraLib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function demo4Reply($status, $payload)
{
    http_response_code($status);
    print json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try
{
    $conn = getConnection();
    $senderIp = getSenderIp();

    $authorized = in_array($senderIp, array('127.0.0.1', '::1'), true);
    if (!$authorized)
    {
        $stmt = $conn->prepare(
            "select 1 from partnerRouter where (INET_ATON(?) & nettmask)=(ip & nettmask) limit 1"
        );
        $stmt->bind_param("s", $senderIp);
        $stmt->execute();
        $authorized = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    }

    if (!$authorized)
        demo4Reply(403, array('ok' => false, 'error' => 'registered_tarasec_gateway_required'));

    if ($_SERVER['REQUEST_METHOD'] === 'POST')
    {
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($body)) $body = $_POST;
        $routerId = (int)($body['router_id'] ?? 0);
        $state = strtolower(trim((string)($body['state'] ?? '')));
        $message = mb_substr(trim((string)($body['message'] ?? '')), 0, 255);
        if ($routerId < 1 || !in_array($state, array('configured','applied','error'), true))
            demo4Reply(400, array('ok'=>false,'error'=>'invalid_route_state'));
        $check=$conn->prepare("select 1 from partnerRouter where routerId=? and taggedTrafficRoute is not null");
        $check->bind_param('i',$routerId); $check->execute(); $exists=$check->get_result()->num_rows>0; $check->close();
        if (!$exists) demo4Reply(404,array('ok'=>false,'error'=>'route_not_found'));
        $report=$conn->prepare("insert into demo4GatewayState(gatewayIp,routerId,state,message) values(INET_ATON(?),?,?,?) on duplicate key update state=values(state),message=values(message),reported=current_timestamp");
        $report->bind_param('siss',$senderIp,$routerId,$state,$message); $report->execute(); $report->close();
        demo4Reply(200,array('ok'=>true,'routerId'=>$routerId,'state'=>$state,'reportedAt'=>gmdate('c')));
    }

    $sql = "select r.routerId, p.name partnerName, INET_NTOA(r.ip) destinationIp, " .
        "INET_NTOA(r.nettmask) netmask, INET_NTOA(r.taggedTrafficRoute) taggedTrafficRoute, " .
        "r.taggedTrafficRouteUpdated, COALESCE(s.state,'configured') applyState, " .
        "s.message applyMessage,s.reported,IF(g.demo4RouterId=r.routerId,1,0) selected " .
        "from partnerRouter r join partner p on p.partnerId=r.partnerId " .
        "left join demo4GatewayState s on s.routerId=r.routerId and s.gatewayIp=INET_ATON(?) " .
        "left join gatewayDemoConfiguration g on g.gatewayIp=INET_ATON(?) " .
        "where r.taggedTrafficRoute is not null order by selected desc,p.name,r.ip";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss',$senderIp,$senderIp);
    $stmt->execute();
    $result = $stmt->get_result();
    $routes = array();
    while ($row = $result->fetch_assoc())
    {
        $routes[] = array(
            'routerId' => (int)$row['routerId'],
            'partnerName' => $row['partnerName'],
            'destinationIp' => $row['destinationIp'],
            'netmask' => $row['netmask'],
            'taggedTrafficRoute' => $row['taggedTrafficRoute'],
            'updatedAt' => $row['taggedTrafficRouteUpdated'],
            'selected' => (bool)$row['selected'],
            'applyState' => $row['applyState'],
            'applyMessage' => (string)($row['applyMessage'] ?? ''),
            'reportedAt' => $row['reported']
        );
    }
    $result->free();
    $stmt->close();
    $conn->close();

    demo4Reply(200, array(
        'ok' => true,
        'demo' => 4,
        'sourceIp' => $senderIp,
        'mode' => 'participant_only_fail_closed',
        'internetModel' => 'lan_to_netbird_allowed',
        'stateAuthority' => 'gateway_reported',
        'routes' => $routes
    ));
}
catch (Throwable $e)
{
    error_log('appDemo4.php: '.$e->getMessage());
    demo4Reply(503, array('ok' => false, 'error' => 'demo4_routes_unavailable'));
}
