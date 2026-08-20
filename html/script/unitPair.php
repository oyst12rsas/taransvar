<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pairReply(int $status, array $data): never
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    pairReply(405, ['ok'=>false,'error'=>'post_required']);
}

$peer = peerIpv4();
if (!filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    pairReply(403, ['ok'=>false,'error'=>'invalid_client_ip']);
}

try {
    $conn = getConnection();
    $result = $conn->query("SELECT adminIP,nettmask,COALESCE(nickname,'TaraSec gateway') nickname FROM setup LIMIT 1");
    $setup = $result ? $result->fetch_assoc() : null;
    if ($result) $result->free();
    if (!$setup) {
        $conn->close();
        pairReply(503, ['ok'=>false,'error'=>'gateway_not_configured']);
    }

    $peerLong = ipv4Long($peer);
    $admin = (int)($setup['adminIP'] ?? 0);
    $mask = (int)($setup['nettmask'] ?? 0);
    if ($peerLong === null || $mask === 0 || (($peerLong & $mask) !== ($admin & $mask))) {
        $conn->close();
        pairReply(403, ['ok'=>false,'error'=>'local_network_required']);
    }

    // Resolve the requesting computer to the gateway's own unit record.
    $unit = null;
    $stmt = $conn->prepare("SELECT unitId,ownerId,COALESCE(hostname,'') hostname,COALESCE(description,'') description FROM unit WHERE ipAddress=INET_ATON(?) ORDER BY lastSeen DESC,unitId DESC LIMIT 1");
    $stmt->bind_param('s', $peer);
    $stmt->execute();
    $unit = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$unit) {
        $stmt = $conn->prepare("SELECT u.unitId,u.ownerId,COALESCE(u.hostname,'') hostname,COALESCE(u.description,'') description FROM dhcpEvent d JOIN unit u ON u.unitId=d.unitId WHERE d.yourIp=INET_ATON(?) AND d.unitId IS NOT NULL ORDER BY d.seenAt DESC LIMIT 1");
        $stmt->bind_param('s', $peer);
        $stmt->execute();
        $unit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$unit) {
        $conn->close();
        pairReply(404, ['ok'=>false,'error'=>'unit_not_identified']);
    }

    // Token is shown exactly once. Only the SHA-256 hash is kept on the gateway.
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $label = trim((string)($_POST['label'] ?? ''));
    if (strlen($label) > 100) $label = substr($label, 0, 100);

    $stmt = $conn->prepare("INSERT INTO unitAppToken (unitId,tokenHash,label,expires) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 365 DAY))");
    $unitId = (int)$unit['unitId'];
    $stmt->bind_param('iss', $unitId, $tokenHash, $label);
    $stmt->execute();
    $subscriptionId = (int)$stmt->insert_id;
    $stmt->close();
    $conn->close();

    pairReply(201, [
        'ok'=>true,
        'scope'=>'single_unit_read_only',
        'subscriptionId'=>$subscriptionId,
        'token'=>$token,
        'gateway'=>(string)$setup['nickname'],
        'unit'=>[
            'unitId'=>$unitId,
            'ownerId'=>$unit['ownerId'] === null ? null : (int)$unit['ownerId'],
            'hostname'=>(string)$unit['hostname'],
            'description'=>(string)$unit['description'],
        ],
        'remoteStatusPath'=>'/script/unitStatus.php',
        'privacy'=>[
            'managerAccess'=>false,
            'singleUnitOnly'=>true,
            'personalIdentityReturned'=>false,
            'tokenStoredPlaintextOnGateway'=>false,
        ],
    ]);
} catch (Throwable $e) {
    error_log('unitPair.php: '.$e->getMessage());
    pairReply(503, ['ok'=>false,'error'=>'unit_pairing_unavailable']);
}
