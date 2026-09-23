<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once '../dbfunc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function demo4SessionReply(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
function demo4SessionInput(): array {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($body) ? $body : $_POST;
}
function demo4SessionLoad(mysqli $db, string $id): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return null;
    $stmt = $db->prepare("SELECT sessionId,tokenHash,INET_NTOA(gatewayIp) gatewayIp,
        routerId,INET_NTOA(destinationIp) destinationIp,INET_NTOA(relayIp) relayIp,
        expiresAt,cleanGatewayAt,infectedGatewayAt,
        INET_NTOA(cleanWebsiteIp) cleanWebsiteIp,cleanWebsiteAt,
        INET_NTOA(infectedWebsiteIp) infectedWebsiteIp,infectedWebsiteAt
        FROM demo4Session WHERE sessionId=? LIMIT 1");
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
function demo4SessionAuthorize(array $row, string $token): void {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token) ||
        !hash_equals($row['tokenHash'], hash('sha256', $token)))
        demo4SessionReply(403, ['ok'=>false,'error'=>'invalid_session_token']);
    if (strtotime($row['expiresAt'].' UTC') < time())
        demo4SessionReply(410, ['ok'=>false,'error'=>'session_expired']);
}

try {
    $db = getConnection();
    // Do not use getSenderIp() here: it accepts the spoofable Client-IP header.
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $action = (string)($_REQUEST['action'] ?? '');
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');

    if ($action === 'create') {
        if ($method !== 'POST') demo4SessionReply(405, ['ok'=>false,'error'=>'post_required']);
        if (!filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            demo4SessionReply(403, ['ok'=>false,'error'=>'gateway_required']);
        $route = $db->prepare("SELECT r.routerId,INET_NTOA(r.ip) destinationIp,
            INET_NTOA(r.taggedTrafficRoute) relayIp FROM gatewayDemoConfiguration g
            JOIN partnerRouter r ON r.routerId=g.demo4RouterId
            WHERE g.gatewayIp=INET_ATON(?) AND r.taggedTrafficRoute IS NOT NULL
              AND r.nettmask=4294967295 LIMIT 1");
        $route->bind_param('s', $remote); $route->execute();
        $selected = $route->get_result()->fetch_assoc(); $route->close();
        if (!$selected) demo4SessionReply(403, ['ok'=>false,'error'=>'selected_gateway_route_required']);
        $id = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $routerId = (int)$selected['routerId'];
        $stmt = $db->prepare("INSERT INTO demo4Session
            (sessionId,tokenHash,gatewayIp,routerId,destinationIp,relayIp,expiresAt)
            SELECT ?,?,INET_ATON(?),routerId,ip,taggedTrafficRoute,
                DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)
            FROM partnerRouter WHERE routerId=? AND taggedTrafficRoute IS NOT NULL");
        $stmt->bind_param('sssi', $id,$hash,$remote,$routerId);
        $stmt->execute(); $stmt->close();
        demo4SessionReply(200, ['ok'=>true,'sessionId'=>$id,'token'=>$token,
            'gatewayIp'=>$remote,'routerId'=>$routerId,
            'destinationIp'=>$selected['destinationIp'],
            'relayIp'=>$selected['relayIp'],'expiresIn'=>1800]);
    }

    $input = $method === 'POST' ? demo4SessionInput() : $_GET;
    $id = strtolower((string)($input['session_id'] ?? ''));
    $row = demo4SessionLoad($db, $id);
    if (!$row) demo4SessionReply(404, ['ok'=>false,'error'=>'session_not_found']);

    if ($action === 'status' && $method === 'GET') {
        $expired = strtotime($row['expiresAt'].' UTC') < time();
        demo4SessionReply(200, ['ok'=>true,'sessionId'=>$id,'expired'=>$expired,
            'gatewayIp'=>$row['gatewayIp'],'destinationIp'=>$row['destinationIp'],
            'relayIp'=>$row['relayIp'],
            'clean'=>['gatewayConfirmedAt'=>$row['cleanGatewayAt'],
                'websiteIp'=>$row['cleanWebsiteIp'],'websiteObservedAt'=>$row['cleanWebsiteAt']],
            'infected'=>['gatewayConfirmedAt'=>$row['infectedGatewayAt'],
                'websiteIp'=>$row['infectedWebsiteIp'],'websiteObservedAt'=>$row['infectedWebsiteAt']],
            'routeProven'=>false]);
    }

    if ($method !== 'POST') demo4SessionReply(405, ['ok'=>false,'error'=>'post_required']);
    $token = strtolower((string)($input['token'] ?? ''));
    demo4SessionAuthorize($row, $token);
    $phase = (string)($input['phase'] ?? '');
    if (!in_array($phase, ['clean','infected'], true))
        demo4SessionReply(400, ['ok'=>false,'error'=>'invalid_phase']);

    if ($action === 'gateway') {
        if (!hash_equals($row['gatewayIp'], $remote))
            demo4SessionReply(403, ['ok'=>false,'error'=>'wrong_gateway']);
        $column = $phase === 'clean' ? 'cleanGatewayAt' : 'infectedGatewayAt';
        $stmt = $db->prepare("UPDATE demo4Session SET $column=UTC_TIMESTAMP()
            WHERE sessionId=? AND expiresAt>UTC_TIMESTAMP()");
        $stmt->bind_param('s', $id); $stmt->execute(); $stmt->close();
        demo4SessionReply(200, ['ok'=>true,'phase'=>$phase,'gatewayConfirmed'=>true]);
    }

    if ($action === 'website') {
        $keyFile = '/etc/tarasec/demo4-website.key';
        $expected = is_readable($keyFile) ? trim((string)file_get_contents($keyFile)) : '';
        $supplied = (string)($_SERVER['HTTP_X_TARASEC_DEMO4_KEY'] ?? '');
        if (strlen($expected) < 32 || !hash_equals($expected, $supplied))
            demo4SessionReply(403, ['ok'=>false,'error'=>'website_reporter_required']);
        $ip = (string)($input['observed_ip'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            demo4SessionReply(400, ['ok'=>false,'error'=>'invalid_observed_ip']);
        $columnIp = $phase === 'clean' ? 'cleanWebsiteIp' : 'infectedWebsiteIp';
        $columnAt = $phase === 'clean' ? 'cleanWebsiteAt' : 'infectedWebsiteAt';
        $stmt = $db->prepare("UPDATE demo4Session
            SET $columnIp=INET_ATON(?),$columnAt=UTC_TIMESTAMP()
            WHERE sessionId=? AND expiresAt>UTC_TIMESTAMP()");
        $stmt->bind_param('ss', $ip,$id); $stmt->execute(); $stmt->close();
        demo4SessionReply(200, ['ok'=>true,'phase'=>$phase,'websiteObservedIp'=>$ip]);
    }
    demo4SessionReply(400, ['ok'=>false,'error'=>'unknown_action']);
} catch (Throwable $e) {
    error_log('appDemo4Session.php: '.$e->getMessage());
    demo4SessionReply(503, ['ok'=>false,'error'=>'demo4_session_unavailable']);
}
