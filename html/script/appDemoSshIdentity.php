<?php
declare(strict_types=1);
require_once __DIR__ . '/../deploymentMode.php';
tarasecRequireDemo();

// Gateway-observed identity proof. Never accept an app-supplied unit ID/IP.

ini_set('display_errors', '0');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../dbfunc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function identityReply(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
$peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (str_starts_with($peer, '::ffff:')) $peer = substr($peer, 7);
if (!filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
    identityReply(400, ['ok'=>false, 'error'=>'IPv4 peer required']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
    identityReply(405, ['ok'=>false, 'error'=>'POST required']);
try {
    $db = getConnection();
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    if (($input['action'] ?? '') === 'resolve') {
        // Only this gateway's configured DB servers can redeem a proof.
        $q = $db->prepare('SELECT 1 FROM setup WHERE INET_ATON(?) IN (globalDb1ip,globalDb2ip,globalDb3ip) LIMIT 1');
        $q->bind_param('s', $peer); $q->execute();
        $authorized = (bool)$q->get_result()->fetch_row(); $q->close();
        if (!$authorized) identityReply(403, ['ok'=>false, 'error'=>'DB server required']);
        $token = (string)($input['identity_proof'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $token))
            identityReply(403, ['ok'=>false, 'error'=>'Invalid identity proof']);
        $hash = hash('sha256', $token);
        $q = $db->prepare('SELECT unitId,INET_NTOA(clientIp) client_ip FROM demoSshIdentityProof WHERE tokenHash=? AND expires>NOW() LIMIT 1');
        $q->bind_param('s', $hash); $q->execute();
        $proof = $q->get_result()->fetch_assoc(); $q->close();
        if (!$proof) identityReply(403, ['ok'=>false, 'error'=>'Identity proof expired or unavailable']);
        // Recheck current local policy rather than trusting a cached clean bit.
        $unitId = (int)$proof['unitId']; $clientIp = (string)$proof['client_ip'];
        $q = $db->prepare("SELECT why FROM internalInfections WHERE (unitId=? OR ip=INET_ATON(?)) AND active=b'1' AND severity>1 ORDER BY infectionId DESC LIMIT 1");
        $q->bind_param('is', $unitId, $clientIp); $q->execute();
        $infection = $q->get_result()->fetch_assoc(); $q->close();
        identityReply(200, ['ok'=>true, 'unit_id'=>$unitId, 'eligible'=>!$infection,
            'demo_reset_available'=>$infection && str_starts_with((string)$infection['why'], 'DEMO:')]);
    }
    if (($input['action'] ?? '') !== 'issue')
        identityReply(400, ['ok'=>false, 'error'=>'Unsupported identity action']);
    $q = $db->prepare('SELECT unitId FROM unit WHERE ipAddress=INET_ATON(?) ORDER BY lastSeen DESC,unitId DESC LIMIT 1');
    $q->bind_param('s', $peer); $q->execute();
    $unit = $q->get_result()->fetch_assoc(); $q->close();
    if (!$unit) identityReply(409, ['ok'=>false, 'error'=>'This device is not registered as a unit on the gateway. Ask its operator to register it before Demo 2.']);
    $db->query('DELETE FROM demoSshIdentityProof WHERE expires<NOW()');
    $token = bin2hex(random_bytes(32)); $hash = hash('sha256', $token); $unitId = (int)$unit['unitId'];
    $q = $db->prepare('INSERT INTO demoSshIdentityProof(tokenHash,unitId,clientIp,expires) VALUES(?,?,INET_ATON(?),NOW()+INTERVAL 60 SECOND)');
    $q->bind_param('sis', $hash, $unitId, $peer); $q->execute(); $q->close();
    identityReply(201, ['ok'=>true, 'identity_proof'=>$token, 'expires_in'=>60]);
} catch (Throwable $e) {
    error_log('Demo 2 gateway identity unavailable');
    identityReply(503, ['ok'=>false, 'error'=>'Gateway identity unavailable. Ask its operator to update the Demo 2 API and database.']);
}
