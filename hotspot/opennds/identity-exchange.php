<?php
declare(strict_types=1);
require_once __DIR__ . '/identity-common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') subscriber_reply(405,['ok'=>false,'reason'=>'post_required']);
$code=trim((string)($_POST['code'] ?? ''));
$deviceKey=strtolower(trim((string)($_POST['device_key'] ?? '')));
$deviceLabel=trim((string)($_POST['device_label'] ?? ''));
if ($code==='' || $deviceKey==='') subscriber_reply(400,['ok'=>false,'reason'=>'missing_code_or_device']);
$db=subscriber_db();
$hash=hash('sha256',$code);

try {
    $db->begin_transaction();
    $stmt=$db->prepare("SELECT identityCodeId,identityId FROM tarasecIdentityCode WHERE codeHash=? AND usedAt IS NULL AND expiresAt>NOW() FOR UPDATE");
    $stmt->bind_param('s',$hash);
    $stmt->execute();
    $grant=$stmt->get_result()->fetch_assoc();
    if (!$grant) { $db->rollback(); subscriber_reply(401,['ok'=>false,'reason'=>'identity_code_invalid_or_expired']); }
    $codeId=(int)$grant['identityCodeId'];
    $identityId=(int)$grant['identityId'];
    $db->query("UPDATE tarasecIdentityCode SET usedAt=NOW() WHERE identityCodeId=".$codeId);

    $stmt=$db->prepare("SELECT customerId FROM hotspotCustomer WHERE identityId=? LIMIT 1");
    $stmt->bind_param('i',$identityId);
    $stmt->execute();
    $customer=$stmt->get_result()->fetch_assoc();
    if ($customer) {
        $customerId=(int)$customer['customerId'];
    } else {
        $stmt=$db->prepare("SELECT primaryEmail,emailVerifiedAt FROM tarasecIdentity WHERE identityId=? LIMIT 1");
        $stmt->bind_param('i',$identityId);
        $stmt->execute();
        $identity=$stmt->get_result()->fetch_assoc();
        $email=$identity['primaryEmail'] ?? null;
        $verifiedAt=$identity['emailVerifiedAt'] ?? null;
        $stmt=$db->prepare("INSERT INTO hotspotCustomer(identityId,email,emailVerifiedAt,active) VALUES(?,?,?,b'1')");
        $stmt->bind_param('iss',$identityId,$email,$verifiedAt);
        $stmt->execute();
        $customerId=(int)$db->insert_id;
        $db->query("INSERT INTO hotspotCreditAccount(customerId,balanceCredits) VALUES($customerId,0) ON DUPLICATE KEY UPDATE customerId=customerId");
    }

    $stmt=$db->prepare("INSERT INTO hotspotDevice(customerId,deviceKey) VALUES(?,?) ON DUPLICATE KEY UPDATE customerId=VALUES(customerId),lastSeen=NOW()");
    $stmt->bind_param('is',$customerId,$deviceKey);
    $stmt->execute();

    $plainToken=identity_random();
    $tokenHash=hash('sha256',$plainToken);
    $stmt=$db->prepare("INSERT INTO hotspotSubscriberToken(customerId,tokenHash,deviceLabel,expiresAt) VALUES(?,?,NULLIF(?,''),DATE_ADD(NOW(),INTERVAL 90 DAY))");
    $stmt->bind_param('iss',$customerId,$tokenHash,$deviceLabel);
    $stmt->execute();
    $db->commit();

    subscriber_reply(200,['ok'=>true,'token'=>$plainToken,'customer_id'=>$customerId]);
} catch (Throwable $e) {
    try { $db->rollback(); } catch (Throwable $ignored) {}
    error_log('identity exchange failed: '.$e->getMessage());
    subscriber_reply(500,['ok'=>false,'reason'=>'identity_exchange_failed']);
}
