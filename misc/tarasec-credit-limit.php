#!/usr/bin/env php
<?php
// Approve/change a TaraSec subscriber credit limit by email.
// Example: php misc/tarasec-credit-limit.php user@example.com 500

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only\n"); exit(2); }
$email=trim((string)($argv[1] ?? ''));
$limit=(float)($argv[2] ?? -1);
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || !is_finite($limit) || $limit < 0) {
    fwrite(STDERR,"Usage: php misc/tarasec-credit-limit.php user@example.com LIMIT_CREDITS\n");
    exit(2);
}
$limit=round($limit,6);
$dbBootstrap=getenv('TARASEC_DB_BOOTSTRAP') ?: __DIR__.'/../html/db_connect.php';
if (!is_file($dbBootstrap)) { fwrite(STDERR,"DB bootstrap not found: $dbBootstrap\n"); exit(1); }
require $dbBootstrap;
$db=$mysqli ?? $conn ?? $db ?? null;
if (!($db instanceof mysqli)) { fwrite(STDERR,"No mysqli handle from DB bootstrap.\n"); exit(1); }
$stmt=$db->prepare("SELECT customerId FROM hotspotCustomer WHERE active=b'1' AND LOWER(email)=LOWER(?) LIMIT 2");
$stmt->bind_param('s',$email);
$stmt->execute();
$res=$stmt->get_result();
if ($res->num_rows !== 1) {
    fwrite(STDERR,$res->num_rows===0 ? "No active global subscriber with that email.\n" : "Email is ambiguous; fix duplicate subscriber records first.\n");
    exit(1);
}
$customerId=(int)$res->fetch_assoc()['customerId'];
$db->begin_transaction();
try {
    $stmt=$db->prepare("SELECT creditLimitCredits,debtCredits FROM hotspotCreditFacility WHERE customerId=? FOR UPDATE");
    $stmt->bind_param('i',$customerId);
    $stmt->execute();
    $old=$stmt->get_result()->fetch_assoc();
    $debt=(float)($old['debtCredits'] ?? 0);
    if ($limit + 0.0000005 < $debt) throw new RuntimeException("Limit cannot be below current debt ($debt credits)");
    $status=$limit > 0 ? 'active' : 'disabled';
    $approvedBy=get_current_user() ?: 'cli';
    $stmt=$db->prepare("INSERT INTO hotspotCreditFacility(customerId,creditLimitCredits,debtCredits,status,approvedBy,approvedAt)
                        VALUES(?,?,0,?,?,NOW())
                        ON DUPLICATE KEY UPDATE creditLimitCredits=VALUES(creditLimitCredits),status=VALUES(status),approvedBy=VALUES(approvedBy),approvedAt=NOW()");
    $stmt->bind_param('idss',$customerId,$limit,$status,$approvedBy);
    $stmt->execute();
    $note='Credit limit changed by administrator';
    $stmt=$db->prepare("INSERT INTO hotspotCreditFacilityLedger(customerId,entryType,amountCredits,debtAfter,creditLimitAfter,note)
                        VALUES(?,'limit_change',?,?,?,?)");
    $change=$limit-(float)($old['creditLimitCredits'] ?? 0);
    $stmt->bind_param('iddds',$customerId,$change,$debt,$limit,$note);
    $stmt->execute();
    $db->commit();
    fwrite(STDOUT,"TaraSec credit limit for #$customerId ($email) set to ".number_format($limit,6,'.','')." credits; debt is ".number_format($debt,6,'.','').".\n");
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR,$e->getMessage()."\n");
    exit(1);
}
