<?php
// Draw approved TaraSec credit into the subscriber's spendable credit balance.
// This does not create a facility; an active facility with sufficient unused
// limit must already have been approved.

declare(strict_types=1);
require_once __DIR__ . '/subscriber-api-common.php';

$db=subscriber_db();
$subscriber=subscriber_require($db);
$customerId=(int)$subscriber['customerId'];
$amount=(float)($_POST['amount_credits'] ?? 0);

if (!is_finite($amount) || $amount <= 0.0) {
    subscriber_reply(400,['ok'=>false,'reason'=>'invalid_credit_amount']);
}
$amount=round($amount,6);

try {
    $db->begin_transaction();

    $stmt=$db->prepare("SELECT creditLimitCredits,debtCredits,status
                        FROM hotspotCreditFacility WHERE customerId=? FOR UPDATE");
    $stmt->bind_param('i',$customerId);
    $stmt->execute();
    $facility=$stmt->get_result()->fetch_assoc();
    if (!$facility || $facility['status'] !== 'active') {
        $db->rollback();
        subscriber_reply(403,['ok'=>false,'reason'=>'credit_facility_not_active']);
    }

    $limit=(float)$facility['creditLimitCredits'];
    $oldDebt=(float)$facility['debtCredits'];
    $available=max(0.0,round($limit-$oldDebt,6));
    if ($amount > $available + 0.0000005) {
        $db->rollback();
        subscriber_reply(409,[
            'ok'=>false,
            'reason'=>'credit_limit_exceeded',
            'available_credit'=>number_format($available,6,'.','')
        ]);
    }

    $stmt=$db->prepare("SELECT balanceCredits FROM hotspotCreditAccount WHERE customerId=? FOR UPDATE");
    $stmt->bind_param('i',$customerId);
    $stmt->execute();
    $account=$stmt->get_result()->fetch_assoc();
    if (!$account) {
        $db->rollback();
        subscriber_reply(409,['ok'=>false,'reason'=>'credit_account_missing']);
    }

    $oldBalance=(float)$account['balanceCredits'];
    $newBalance=round($oldBalance+$amount,6);
    $newDebt=round($oldDebt+$amount,6);

    $stmt=$db->prepare("UPDATE hotspotCreditAccount SET balanceCredits=? WHERE customerId=?");
    $stmt->bind_param('di',$newBalance,$customerId);
    $stmt->execute();

    $stmt=$db->prepare("UPDATE hotspotCreditFacility SET debtCredits=? WHERE customerId=?");
    $stmt->bind_param('di',$newDebt,$customerId);
    $stmt->execute();

    $note='Approved TaraSec credit draw';
    $stmt=$db->prepare("INSERT INTO hotspotCreditLedger(customerId,entryType,amountCredits,balanceAfter,note)
                        VALUES(?,'adjustment',?,?,?)");
    $stmt->bind_param('idds',$customerId,$amount,$newBalance,$note);
    $stmt->execute();

    $stmt=$db->prepare("INSERT INTO hotspotCreditFacilityLedger
        (customerId,entryType,amountCredits,debtAfter,creditLimitAfter,note)
        VALUES(?,'draw',?,?,?,?,?)");
    $stmt->bind_param('iddds',$customerId,$amount,$newDebt,$limit,$note);
    $stmt->execute();

    $db->commit();
    subscriber_reply(200,[
        'ok'=>true,
        'drawn_credits'=>number_format($amount,6,'.',''),
        'balance_credits'=>number_format($newBalance,6,'.',''),
        'debt_credits'=>number_format($newDebt,6,'.',''),
        'credit_limit_credits'=>number_format($limit,6,'.',''),
        'available_credit'=>number_format(max(0.0,$limit-$newDebt),6,'.','')
    ]);
} catch (Throwable $e) {
    try { $db->rollback(); } catch (Throwable $ignored) {}
    error_log('TaraSec credit draw failed: '.$e->getMessage());
    subscriber_reply(500,['ok'=>false,'reason'=>'credit_draw_failed']);
}
