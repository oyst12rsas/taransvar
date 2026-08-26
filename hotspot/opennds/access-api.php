<?php
// TaraSec hotspot access decision endpoint.
// Deploy behind HTTPS on the TaraSec/global DB web service.
// The gateway authenticates with X-TaraSec-Gateway-Key and X-TaraSec-Token.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

function reply(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

$gatewayKey = trim((string)($_SERVER['HTTP_X_TARASEC_GATEWAY_KEY'] ?? ''));
$token = (string)($_SERVER['HTTP_X_TARASEC_TOKEN'] ?? '');
$deviceKey = strtolower(trim((string)($_POST['device_key'] ?? $_GET['device_key'] ?? '')));
$clientIp = trim((string)($_POST['client_ip'] ?? $_GET['client_ip'] ?? ''));

if ($gatewayKey === '' || $token === '' || $deviceKey === '') {
    reply(400, ['ok'=>false, 'allow'=>false, 'reason'=>'missing_credentials']);
}

// Site-specific DB bootstrap. Keep credentials outside this endpoint.
$dbBootstrap = getenv('TARASEC_DB_BOOTSTRAP') ?: __DIR__ . '/../../html/db_connect.php';
if (!is_file($dbBootstrap)) reply(500, ['ok'=>false,'allow'=>false,'reason'=>'db_bootstrap_missing']);
require_once $dbBootstrap;

// Accept the mysqli handle names used by TaraSec deployments.
$db = $mysqli ?? $conn ?? $db ?? null;
if (!($db instanceof mysqli)) reply(500, ['ok'=>false,'allow'=>false,'reason'=>'db_handle_missing']);

$tokenHash = hash('sha256', $token);
$stmt = $db->prepare("SELECT gatewayId FROM hotspotGateway WHERE gatewayKey=? AND apiTokenHash=? AND active=b'1' LIMIT 1");
$stmt->bind_param('ss', $gatewayKey, $tokenHash);
$stmt->execute();
$gateway = $stmt->get_result()->fetch_assoc();
if (!$gateway) reply(403, ['ok'=>false,'allow'=>false,'reason'=>'gateway_auth_failed']);
$gatewayId = (int)$gateway['gatewayId'];
$db->query("UPDATE hotspotGateway SET lastSeen=CURRENT_TIMESTAMP WHERE gatewayId=".$gatewayId);

$stmt = $db->prepare("SELECT d.deviceId,d.customerId,e.entitlementId,e.validUntil
 FROM hotspotDevice d
 JOIN hotspotEntitlement e ON e.customerId=d.customerId
 WHERE d.deviceKey=? AND e.status='active' AND e.validFrom<=NOW() AND e.validUntil>NOW()
 ORDER BY e.validUntil DESC LIMIT 1");
$stmt->bind_param('s', $deviceKey);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) reply(200, ['ok'=>true,'allow'=>false,'reason'=>'no_active_entitlement']);

$deviceId=(int)$row['deviceId'];
$entitlementId=(int)$row['entitlementId'];
$stmt = $db->prepare("SELECT sessionId FROM hotspotSession WHERE deviceId=? AND providerGatewayId=? AND endedAt IS NULL ORDER BY sessionId DESC LIMIT 1");
$stmt->bind_param('ii',$deviceId,$gatewayId);
$stmt->execute();
$session=$stmt->get_result()->fetch_assoc();
if ($session) {
    $sessionId=(int)$session['sessionId'];
    $db->query("UPDATE hotspotSession SET lastSeen=NOW() WHERE sessionId=".$sessionId);
} else {
    $stmt=$db->prepare("INSERT INTO hotspotSession(entitlementId,deviceId,providerGatewayId) VALUES(?,?,?)");
    $stmt->bind_param('iii',$entitlementId,$deviceId,$gatewayId);
    $stmt->execute();
    $sessionId=(int)$db->insert_id;
}

reply(200, [
    'ok'=>true,
    'allow'=>true,
    'reason'=>'active_entitlement',
    'session_id'=>$sessionId,
    'entitlement_id'=>$entitlementId,
    'valid_until'=>$row['validUntil'],
    'client_ip'=>$clientIp
]);
