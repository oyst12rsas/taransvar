<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function unitReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function peerIpv4(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (strncasecmp($ip, '::ffff:', 7) === 0) $ip = substr($ip, 7);
    return $ip;
}

function ipv4Long(string $ip): ?int
{
    $packed = @inet_pton($ip);
    if ($packed === false || strlen($packed) !== 4) return null;
    $v = unpack('N', $packed);
    return isset($v[1]) ? (int)$v[1] : null;
}

$peer = peerIpv4();
if (!filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    unitReply(403, ['ok'=>false,'error'=>'invalid_client_ip']);
}

try {
    $conn = getConnection();

    // This endpoint is deliberately local-only. It proves "I am currently this
    // LAN unit" from the connection observed by the gateway; it is not a remote
    // bearer-token API and does not grant manager authority.
    $result = $conn->query('SELECT adminIP,nettmask,COALESCE(nickname,\'TaraSec gateway\') nickname FROM setup LIMIT 1');
    $setup = $result ? $result->fetch_assoc() : null;
    if ($result) $result->free();
    if (!$setup) {
        $conn->close();
        unitReply(503, ['ok'=>false,'error'=>'gateway_not_configured']);
    }

    $peerLong = ipv4Long($peer);
    $admin = (int)($setup['adminIP'] ?? 0);
    $mask = (int)($setup['nettmask'] ?? 0);
    if ($peerLong === null || $mask === 0 || (($peerLong & $mask) !== ($admin & $mask))) {
        $conn->close();
        unitReply(403, ['ok'=>false,'error'=>'local_network_required']);
    }

    // Prefer the canonical unit table. DHCP events are a useful fallback while
    // DHCP/unit synchronization is being repaired and tested.
    $unit = null;
    $stmt = $conn->prepare("SELECT unitId,ownerId,INET_NTOA(ipAddress) ip,COALESCE(hostname,'') hostname,COALESCE(description,'') description,lastSeen FROM unit WHERE ipAddress=INET_ATON(?) ORDER BY lastSeen DESC,unitId DESC LIMIT 1");
    $stmt->bind_param('s', $peer);
    $stmt->execute();
    $unit = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$unit) {
        $stmt = $conn->prepare("SELECT u.unitId,u.ownerId,INET_NTOA(u.ipAddress) ip,COALESCE(u.hostname,'') hostname,COALESCE(u.description,'') description,u.lastSeen FROM dhcpEvent d JOIN unit u ON u.unitId=d.unitId WHERE d.yourIp=INET_ATON(?) AND d.unitId IS NOT NULL ORDER BY d.seenAt DESC LIMIT 1");
        $stmt->bind_param('s', $peer);
        $stmt->execute();
        $unit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$unit) {
        $conn->close();
        unitReply(404, [
            'ok'=>false,
            'error'=>'unit_not_identified',
            'clientIp'=>$peer,
            'message'=>'The gateway sees this client, but DHCP/unit identity has not been resolved yet.'
        ]);
    }

    $unitId = (int)$unit['unitId'];
    $infection = null;
    $stmt = $conn->prepare("SELECT infectionId,status,severity,why,lastSeen,CAST(active AS UNSIGNED) active FROM internalInfections WHERE unitId=? ORDER BY active DESC,lastSeen DESC,infectionId DESC LIMIT 1");
    $stmt->bind_param('i', $unitId);
    $stmt->execute();
    $infection = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $recentThreats = 0;
    $maxThreatSeverity = 0;
    $stmt = $conn->prepare("SELECT COUNT(*) cnt,COALESCE(MAX(severity),0) severity FROM syslogThreat WHERE COALESCE(confirmed_unit_id,unit_id)=? AND COALESCE(lastSeen,created)>=NOW()-INTERVAL 24 HOUR");
    $stmt->bind_param('i', $unitId);
    $stmt->execute();
    $threat = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($threat) {
        $recentThreats = (int)$threat['cnt'];
        $maxThreatSeverity = (int)$threat['severity'];
    }

    $infectionActive = $infection && (int)$infection['active'] === 1;
    $infectionSeverity = $infection ? (int)$infection['severity'] : 0;
    $severity = max($infectionSeverity, $maxThreatSeverity);

    $conn->close();
    unitReply(200, [
        'ok'=>true,
        'scope'=>'this_local_unit_only',
        'gateway'=>(string)$setup['nickname'],
        'clientIp'=>$peer,
        'unit'=>[
            'unitId'=>$unitId,
            'ownerId'=>$unit['ownerId'] === null ? null : (int)$unit['ownerId'],
            'hostname'=>(string)$unit['hostname'],
            'description'=>(string)$unit['description'],
            'lastSeen'=>$unit['lastSeen'],
        ],
        'threat'=>[
            'warning'=>$infectionActive || $severity > 1,
            'confirmedLocalInfection'=>$infectionActive,
            'severity'=>$severity,
            'infectionStatus'=>$infection ? $infection['status'] : null,
            'why'=>$infection ? $infection['why'] : null,
            'recentThreatRecords24h'=>$recentThreats,
            'maxThreatSeverity24h'=>$maxThreatSeverity,
        ],
        'privacy'=>[
            'managerAccess'=>false,
            'remoteAccess'=>false,
            'personalIdentityReturned'=>false,
        ],
        'server_time'=>gmdate('c')
    ]);
} catch (Throwable $e) {
    error_log('unitSelf.php: '.$e->getMessage());
    unitReply(503, ['ok'=>false,'error'=>'unit_status_unavailable']);
}
