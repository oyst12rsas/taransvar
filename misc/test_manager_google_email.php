<?php
require __DIR__.'/../html/gatekeeper/managerGoogleEmail.php';
function emailExpect(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$session = ['userid'=>7, 'isAdmin'=>1];
emailExpect(managerVerifiedGoogleEmail($session) === null, 'Password login is not Google proof');
managerRecordGoogleIdentity($session, 7, 'Owner@Example.org');
emailExpect(managerVerifiedGoogleEmail($session) === 'owner@example.org', 'Verified email canonicalized');
$now = $session['gatekeeper_google_identity']['verifiedAt'];
emailExpect(managerVerifiedGoogleEmail($session, $now+1801) === null, 'Expired proof rejected');
emailExpect(managerVerifiedGoogleEmail($session, $now-1) === null, 'Future proof rejected');
$changed = $session; $changed['userid'] = 8;
emailExpect(managerVerifiedGoogleEmail($changed) === null, 'Proof cannot move to another user');
$changed = $session; $changed['isAdmin'] = 0;
emailExpect(managerVerifiedGoogleEmail($changed) === null, 'Ordinary session rejected');
$changed = $session; $changed['gatekeeper_google_identity']['email'] = "owner@example.org\r\nForged";
emailExpect(managerVerifiedGoogleEmail($changed) === null, 'Invalid email rejected');
unset($session['gatekeeper_google_identity']);
emailExpect(managerVerifiedGoogleEmail($session) === null, 'Cleared proof not reusable');
echo "Google email proof expiry, user binding and password isolation passed.\n";
