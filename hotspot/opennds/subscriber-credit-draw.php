<?php
// Subscriber-facing credit draw. Financial approval/debt is authoritative in
// private tarasec_payment; this endpoint only delivers an approved central grant
// into the spendable TaraSec credit cache exactly once.

declare(strict_types=1);
require_once __DIR__ . '/subscriber-api-common.php';
require_once __DIR__ . '/payment-service-client.php';

$db=subscriber_db();
$subscriber=subscriber_require($db);
$customerId=(int)$subscriber['customerId'];
$amount=(float)($_POST['amount_credits'] ?? 0);
if(!is_finite($amount)||$amount<=0.0) subscriber_reply(400,['ok'=>false,'reason'=>'invalid_credit_amount']);
$amount=round($amount,6);
$requestRef='core-'.$customerId.'-'.bin2hex(random_bytes(16));

try {
    $central=tarasec_payment_request('draw','POST',[
        'subscriber_ref'=>(string)$customerId,
        'amount'=>$amount,
        'request_ref'=>$requestRef
    ]);
    $grantId=(string)($central['grant_id']??'');
    $grantAmount=(float)($central['drawn_credits']??0);
    if($grantId===''||$grantAmount<=0.0) throw new RuntimeException('central_credit_grant_invalid');

    $db->begin_transaction();
    $stmt=$db->prepare('SELECT grantId FROM hotspotCreditGrantReceipt WHERE grantId=? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('s',$grantId); $stmt->execute();
    $alreadyApplied=(bool)$stmt->get_result()->fetch_assoc();
    if(!$alreadyApplied){
        $stmt=$db->prepare('SELECT balanceCredits FROM hotspotCreditAccount WHERE customerId=? FOR UPDATE');
        $stmt->bind_param('i',$customerId); $stmt->execute(); $acct=$stmt->get_result()->fetch_assoc();
        if(!$acct) throw new RuntimeException('credit_account_missing');
        $newBalance=round((float)$acct['balanceCredits']+$grantAmount,6);
        $stmt=$db->prepare('UPDATE hotspotCreditAccount SET balanceCredits=? WHERE customerId=?');
        $stmt->bind_param('di',$newBalance,$customerId); $stmt->execute();
        $stmt=$db->prepare('INSERT INTO hotspotCreditGrantReceipt(grantId,customerId,amountCredits) VALUES(?,?,?)');
        $stmt->bind_param('sid',$grantId,$customerId,$grantAmount); $stmt->execute();
        $note='Central TaraSec credit grant '.$grantId;
        $stmt=$db->prepare("INSERT INTO hotspotCreditLedger(customerId,entryType,amountCredits,balanceAfter,note) VALUES(?,'adjustment',?,?,?)");
        $stmt->bind_param('idds',$customerId,$grantAmount,$newBalance,$note); $stmt->execute();
    } else {
        $stmt=$db->prepare('SELECT balanceCredits FROM hotspotCreditAccount WHERE customerId=?');
        $stmt->bind_param('i',$customerId); $stmt->execute(); $newBalance=(float)$stmt->get_result()->fetch_assoc()['balanceCredits'];
    }
    $db->commit();

    $acknowledged=true;
    try {
        tarasec_payment_request('ack','POST',['subscriber_ref'=>(string)$customerId,'grant_id'=>$grantId]);
    } catch(Throwable $ackError) {
        $acknowledged=false;
        error_log('TaraSec credit grant delivered but central ACK pending: '.$ackError->getMessage());
    }

    subscriber_reply(200,[
        'ok'=>true,
        'grant_id'=>$grantId,
        'already_applied'=>$alreadyApplied,
        'central_acknowledged'=>$acknowledged,
        'drawn_credits'=>number_format($grantAmount,6,'.',''),
        'balance_credits'=>number_format($newBalance,6,'.',''),
        'debt_credits'=>(string)($central['debt']??'0.000000'),
        'credit_limit_credits'=>(string)($central['credit_limit']??'0.000000'),
        'available_credit'=>(string)($central['available_credit']??'0.000000')
    ]);
} catch(Throwable $e) {
    try { $db->rollback(); } catch(Throwable $ignored) {}
    error_log('TaraSec central credit draw failed: '.$e->getMessage());
    $reason=str_replace(' ','_',strtolower($e->getMessage()));
    subscriber_reply(502,['ok'=>false,'reason'=>$reason?:'central_credit_draw_failed']);
}
