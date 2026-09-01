<?php
// TaraSec hotspot access decision endpoint.
// A TaraSec subscriber may use any participating gateway. Access is based on
// the subscriber's global TaraSec credit balance; the serving gateway's local
// price is snapshotted onto the session for later accounting.

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

$dbBootstrap = getenv('TARASEC_DB_BOOTSTRAP') ?: __DIR__ . '/../../html/db_connect.php';
if (!is_file($dbBootstrap)) reply(500, ['ok'=>false,'allow'=>false,'reason'=>'db_bootstrap_missing']);
require_once $dbBootstrap;
$db = $mysqli ?? $conn ?? $db ?? null;
if (!($db instanceof mysqli)) reply(500, ['ok'=>false,'allow'=>false,'reason'=>'db_handle_missing']);

$tokenHash = hash('sha256', $token);
$stmt = $db->prepare("SELECT g.gatewayId,g.ownerId,g.countryCode,g.priceCreditsPerMiB,g.providerRewardBps,g.priceLabel
 FROM hotspotGateway g
 WHERE g.gatewayKey=? AND g.apiTokenHash=? AND g.active=b'1' LIMIT 1");
$stmt->bind_param('ss', $gatewayKey, $tokenHash);
$stmt->execute();
$gateway = $stmt->get_result()->fetch_assoc();
if (!$gateway) reply(403, ['ok'=>false,'allow'=>false,'reason'=>'gateway_auth_failed']);

$gatewayId = (int)$gateway['gatewayId'];
$priceCreditsPerMiB = (string)$gateway['priceCreditsPerMiB'];
$providerRewardBps = (int)$gateway['providerRewardBps'];
$db->query("UPDATE hotspotGateway SET lastSeen=CURRENT_TIMESTAMP WHERE gatewayId=".$gatewayId);

// Device identity maps to a network-wide TaraSec subscriber. No home-hotspot
// relationship is consulted here.
$stmt = $db->prepare("SELECT d.deviceId,d.customerId,a.balanceCredits
 FROM hotspotDevice d
 JOIN hotspotCustomer c ON c.customerId=d.customerId AND c.active=b'1'
 JOIN hotspotCreditAccount a ON a.customerId=d.customerId
 WHERE d.deviceKey=? LIMIT 1");
$stmt->bind_param('s', $deviceKey);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) reply(200, ['ok'=>true,'allow'=>false,'reason'=>'unknown_or_inactive_subscriber']);

$balanceCredits = (float)$row['balanceCredits'];
if ($balanceCredits <= 0.0) {
    reply(200, [
        'ok'=>true,
        'allow'=>false,
        'reason'=>'credit_balance_empty',
        'balance_credits'=>'0.000000',
        'price_credits_per_mib'=>$priceCreditsPerMiB,
        'price_label'=>$gateway['priceLabel']
    ]);
}

$deviceId=(int)$row['deviceId'];
$customerId=(int)$row['customerId'];
$stmt = $db->prepare("SELECT sessionId,priceCreditsPerMiB,providerRewardBps
 FROM hotspotSession
 WHERE deviceId=? AND providerGatewayId=? AND endedAt IS NULL
 ORDER BY sessionId DESC LIMIT 1");
$stmt->bind_param('ii',$deviceId,$gatewayId);
$stmt->execute();
$session=$stmt->get_result()->fetch_assoc();

if ($session) {
    $sessionId=(int)$session['sessionId'];
    $priceCreditsPerMiB=(string)$session['priceCreditsPerMiB'];
    $providerRewardBps=(int)$session['providerRewardBps'];
    $db->query("UPDATE hotspotSession SET lastSeen=NOW() WHERE sessionId=".$sessionId);
} else {
    $stmt=$db->prepare("INSERT INTO hotspotSession(customerId,entitlementId,deviceId,providerGatewayId,priceCreditsPerMiB,providerRewardBps)
                        VALUES(?,NULL,?,?,?,?)");
    $stmt->bind_param('iiidi',$customerId,$deviceId,$gatewayId,$priceCreditsPerMiB,$providerRewardBps);
    $stmt->execute();
    $sessionId=(int)$db->insert_id;
}

reply(200, [
    'ok'=>true,
    'allow'=>true,
    'reason'=>'tarasec_credit_available',
    'session_id'=>$sessionId,
    'customer_id'=>$customerId,
    'balance_credits'=>number_format($balanceCredits, 6, '.', ''),
    'price_credits_per_mib'=>$priceCreditsPerMiB,
    'price_label'=>$gateway['priceLabel'],
    'provider_reward_bps'=>$providerRewardBps,
    'client_ip'=>$clientIp
]);
