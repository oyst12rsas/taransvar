<?php
// TaraSec hotspot cumulative usage accounting endpoint.
// Gateways post cumulative byte counters for a session. The endpoint charges
// the subscriber at the session's price snapshot and credits the serving
// hotspot owner's reward account according to the session reward snapshot.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

function reply(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

$gatewayKey = trim((string)($_SERVER['HTTP_X_TARASEC_GATEWAY_KEY'] ?? ''));
$token = (string)($_SERVER['HTTP_X_TARASEC_TOKEN'] ?? '');
$sessionId = (int)($_POST['session_id'] ?? $_GET['session_id'] ?? 0);
$bytesUp = (int)($_POST['bytes_up'] ?? $_GET['bytes_up'] ?? -1);
$bytesDown = (int)($_POST['bytes_down'] ?? $_GET['bytes_down'] ?? -1);
$final = filter_var($_POST['final'] ?? $_GET['final'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($gatewayKey === '' || $token === '' || $sessionId <= 0 || $bytesUp < 0 || $bytesDown < 0) {
    reply(400, ['ok'=>false, 'reason'=>'missing_or_invalid_parameters']);
}

$dbBootstrap = getenv('TARASEC_DB_BOOTSTRAP') ?: __DIR__ . '/../../html/db_connect.php';
if (!is_file($dbBootstrap)) reply(500, ['ok'=>false,'reason'=>'db_bootstrap_missing']);
require_once $dbBootstrap;
$db = $mysqli ?? $conn ?? $db ?? null;
if (!($db instanceof mysqli)) reply(500, ['ok'=>false,'reason'=>'db_handle_missing']);

$tokenHash = hash('sha256', $token);
$stmt = $db->prepare("SELECT g.gatewayId,g.ownerId,COALESCE(g.countryCode,o.primaryCountry) settlementCountry,
                             COALESCE(o.cashEligible,b'0') cashEligible
                      FROM hotspotGateway g
                      LEFT JOIN hotspotOwner o ON o.ownerId=g.ownerId
                      WHERE g.gatewayKey=? AND g.apiTokenHash=? AND g.active=b'1' LIMIT 1");
$stmt->bind_param('ss', $gatewayKey, $tokenHash);
$stmt->execute();
$gateway = $stmt->get_result()->fetch_assoc();
if (!$gateway) reply(403, ['ok'=>false,'reason'=>'gateway_auth_failed']);
$gatewayId=(int)$gateway['gatewayId'];

try {
    $db->begin_transaction();

    $stmt=$db->prepare("SELECT sessionId,customerId,providerGatewayId,priceCreditsPerMiB,providerRewardBps,
                               chargedCredits,providerCredits,bytesUp,bytesDown,endedAt
                        FROM hotspotSession WHERE sessionId=? FOR UPDATE");
    $stmt->bind_param('i',$sessionId);
    $stmt->execute();
    $session=$stmt->get_result()->fetch_assoc();
    if (!$session || (int)$session['providerGatewayId'] !== $gatewayId) {
        $db->rollback();
        reply(404, ['ok'=>false,'reason'=>'session_not_found_for_gateway']);
    }
    if ($session['endedAt'] !== null) {
        $db->rollback();
        reply(409, ['ok'=>false,'reason'=>'session_already_closed']);
    }

    $oldUp=(int)$session['bytesUp'];
    $oldDown=(int)$session['bytesDown'];
    if ($bytesUp < $oldUp || $bytesDown < $oldDown) {
        $db->rollback();
        reply(409, ['ok'=>false,'reason'=>'counters_moved_backwards']);
    }

    $customerId=(int)$session['customerId'];
    $stmt=$db->prepare("SELECT balanceCredits FROM hotspotCreditAccount WHERE customerId=? FOR UPDATE");
    $stmt->bind_param('i',$customerId);
    $stmt->execute();
    $account=$stmt->get_result()->fetch_assoc();
    if (!$account) {
        $db->rollback();
        reply(409, ['ok'=>false,'reason'=>'credit_account_missing']);
    }

    $deltaBytes=($bytesUp-$oldUp)+($bytesDown-$oldDown);
    $usageMiB=$deltaBytes/1048576.0;
    $price=(float)$session['priceCreditsPerMiB'];
    $requestedCharge=round($usageMiB*$price,6);
    $oldBalance=(float)$account['balanceCredits'];
    $charge=min($requestedCharge,max(0.0,$oldBalance));
    $newBalance=round($oldBalance-$charge,6);
    $reward=round($charge*((int)$session['providerRewardBps']/10000.0),6);
    $newCharged=round((float)$session['chargedCredits']+$charge,6);
    $newProvider=round((float)$session['providerCredits']+$reward,6);
    $exhausted=($newBalance <= 0.0000005 && $requestedCharge > 0.0);

    $sql="UPDATE hotspotSession SET bytesUp=?,bytesDown=?,lastSeen=NOW(),chargedCredits=?,providerCredits=?".
         (($final || $exhausted) ? ",endedAt=NOW()" : "")." WHERE sessionId=?";
    $stmt=$db->prepare($sql);
    $stmt->bind_param('iiddi',$bytesUp,$bytesDown,$newCharged,$newProvider,$sessionId);
    $stmt->execute();

    if ($charge > 0.0) {
        $stmt=$db->prepare("UPDATE hotspotCreditAccount SET balanceCredits=? WHERE customerId=?");
        $stmt->bind_param('di',$newBalance,$customerId);
        $stmt->execute();

        $negativeCharge=-$charge;
        $note='Hotspot usage';
        $stmt=$db->prepare("INSERT INTO hotspotCreditLedger(customerId,sessionId,gatewayId,entryType,amountCredits,balanceAfter,note)
                            VALUES(?,?,?,'usage',?,?,?)");
        $stmt->bind_param('iiidds',$customerId,$sessionId,$gatewayId,$negativeCharge,$newBalance,$note);
        $stmt->execute();

        $ownerId=(int)($gateway['ownerId'] ?? 0);
        $country=(string)($gateway['settlementCountry'] ?? '');
        if ($ownerId > 0 && $country !== '' && $reward > 0.0) {
            $stmt=$db->prepare("INSERT INTO hotspotOwnerAccount(ownerId,balanceCredits)
                                VALUES(?,?) ON DUPLICATE KEY UPDATE balanceCredits=balanceCredits+VALUES(balanceCredits)");
            $stmt->bind_param('id',$ownerId,$reward);
            $stmt->execute();

            $cashEligible=((int)$gateway['cashEligible']) ? 1 : 0;
            $stmt=$db->prepare("INSERT INTO hotspotProviderLedger
                (ownerId,gatewayId,sessionId,usageMiB,subscriberCreditsCharged,providerCreditsEarned,settlementCountry,cashEligibleSnapshot)
                VALUES(?,?,?,?,?,?,?,?)");
            $stmt->bind_param('iiidddsi',$ownerId,$gatewayId,$sessionId,$usageMiB,$charge,$reward,$country,$cashEligible);
            $stmt->execute();
        }
    }

    $db->commit();
    reply(200, [
        'ok'=>true,
        'allow'=>!$exhausted && !$final,
        'session_id'=>$sessionId,
        'delta_bytes'=>$deltaBytes,
        'usage_mib'=>number_format($usageMiB,6,'.',''),
        'charged_credits'=>number_format($charge,6,'.',''),
        'provider_credits'=>number_format($reward,6,'.',''),
        'balance_credits'=>number_format($newBalance,6,'.',''),
        'credit_exhausted'=>$exhausted,
        'closed'=>$final || $exhausted
    ]);
} catch (Throwable $e) {
    try { $db->rollback(); } catch (Throwable $ignored) {}
    error_log('TaraSec hotspot accounting failed: '.$e->getMessage());
    reply(500, ['ok'=>false,'reason'=>'accounting_failed']);
}
