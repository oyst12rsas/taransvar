<?php
require __DIR__.'/../html/gatekeeper/managerGoogleEmail.php';
function googleDbExpect(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', 'test', 'hosted_link_test', 3306);
// Temporary tables isolate these tests from the linking suite's schema/state.
$db->query('CREATE TEMPORARY TABLE user (userId INT PRIMARY KEY, username VARCHAR(200), isAdmin BIT(1), suspendedUntil DATETIME NULL) ENGINE=InnoDB');
$db->query("INSERT INTO user VALUES (1,'owner@example.org',b'1',NULL)");
$db->query('CREATE TEMPORARY TABLE managerRequest (managerRequestId INT PRIMARY KEY, email VARCHAR(200), emailVerifiedTime DATETIME NULL, gatewayApprovedTime DATETIME NULL, rejectedTime DATETIME NULL, expires DATETIME NULL, emailVerifyTokenPlain VARCHAR(64) NULL, emailVerifyTokenHash VARCHAR(64) NULL, active BIT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB');
$db->query("INSERT INTO managerRequest(managerRequestId,email,expires,emailVerifyTokenPlain,emailVerifyTokenHash) VALUES (1,'owner@example.org',NOW()+INTERVAL 1 DAY,'email-secret','email-hash'), (2,'other@example.org',NOW()+INTERVAL 1 DAY,'other-secret','other-hash'), (3,'owner@example.org',NOW()+INTERVAL 1 DAY,'rejected-secret','rejected-hash'), (4,'owner@example.org',NOW()-INTERVAL 1 DAY,'expired-secret','expired-hash'), (5,'owner@example.org',NOW()+INTERVAL 1 DAY,'second-secret','second-hash')");
$db->query('UPDATE managerRequest SET rejectedTime=NOW() WHERE managerRequestId=3');
$session = ['userid'=>1,'isAdmin'=>1];
googleDbExpect(managerConfirmGoogleEmail($db,$session,1) === 0, 'Password session cannot verify email');
managerRecordGoogleIdentity($session,1,'owner@example.org');
googleDbExpect(managerConfirmGoogleEmail($db,$session,2) === 0, 'A different email still needs its link');
googleDbExpect(managerConfirmGoogleEmail($db,$session,1) === 1, 'Matching focused request verified');
$row = $db->query('SELECT * FROM managerRequest WHERE managerRequestId=1')->fetch_assoc();
googleDbExpect($row['emailVerifiedTime'] !== null && $row['gatewayApprovedTime'] === null && (int)$row['active'] === 0, 'Verification cannot grant or activate management');
googleDbExpect($row['emailVerifyTokenPlain'] === null && $row['emailVerifyTokenHash'] === null, 'Old verification link invalidated');
googleDbExpect(managerConfirmGoogleEmail($db,$session,1) === 0, 'Verification retry is idempotent');
googleDbExpect($db->query('SELECT emailVerifiedTime FROM managerRequest WHERE managerRequestId=5')->fetch_row()[0] === null, 'Focused request does not verify another pending request');
googleDbExpect(managerConfirmGoogleEmail($db,$session,3) === 0, 'Rejected request stays rejected');
googleDbExpect(managerConfirmGoogleEmail($db,$session,4) === 0, 'Expired request not verified');
$db->query('UPDATE user SET isAdmin=0 WHERE userId=1');
googleDbExpect(managerConfirmGoogleEmail($db,$session,5) === 0, 'Stale admin session cannot verify after demotion');
$db->query("UPDATE user SET isAdmin=1,suspendedUntil=NOW()+INTERVAL 1 DAY WHERE userId=1");
googleDbExpect(managerConfirmGoogleEmail($db,$session,5) === 0, 'Suspended administrator cannot verify');
$db->query("UPDATE user SET suspendedUntil=NULL,username='different@example.org' WHERE userId=1");
googleDbExpect(managerConfirmGoogleEmail($db,$session,5) === 0, 'Changed administrator identity cannot reuse proof');
$db->query("UPDATE user SET username='owner@example.org' WHERE userId=1");
googleDbExpect(managerConfirmGoogleEmail($db,$session) === 1, 'General approvals confirms only matching eligible requests');
echo "Matching Google email, explicit permission separation, focus, expiry, rejection, demotion and suspension passed.\n";
