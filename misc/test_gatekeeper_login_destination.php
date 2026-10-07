<?php
require __DIR__.'/../html/gatekeeper/loginDestination.php';
function expectDestination($actual, $expected): void {
    if ($actual !== $expected) throw new RuntimeException('Login destination regression.');
}
$_SESSION = [];
expectDestination(gatekeeperTakeLoginDestination(), 'index.php?f=main');
$_GET = ['f'=>'managerApprovals', 'requestId'=>'42'];
gatekeeperRememberApprovalDestination();
$returnTo = gatekeeperTakeLoginDestination();
expectDestination($returnTo, 'index.php?f=managerApprovals&requestId=42');
expectDestination(gatekeeperSafeLoginDestination($returnTo), $returnTo);
expectDestination(gatekeeperTakeLoginDestination(), 'index.php?f=main');
$_GET = ['f'=>'managerApprovals'];
gatekeeperRememberApprovalDestination();
expectDestination(gatekeeperTakeLoginDestination(), 'index.php?f=managerApprovals');
$_SESSION['gatekeeper_login_destination'] = ['requestId'=>42, 'until'=>time()-1];
expectDestination(gatekeeperTakeLoginDestination(), 'index.php?f=main');
foreach (['https://example.org/', '//example.org/', "index.php?f=managerApprovals\r\nLocation: https://example.org/", 'index.php?f=setup', 'index.php?f=managerApprovals&requestId=42&next=https://example.org/', 'index.php?f=managerApprovals&requestId=2147483648', [], null] as $target) {
    expectDestination(gatekeeperSafeLoginDestination($target), 'index.php?f=main');
}
foreach (['0', '-1', '01', '1e2', '1 OR 1=1', '2147483648', [], null] as $id) {
    expectDestination(gatekeeperApprovalRequestId($id), null);
}
expectDestination(gatekeeperApprovalRequestId('2147483647'), 2147483647);
$_GET = ['f'=>'setup', 'requestId'=>'42'];
gatekeeperRememberApprovalDestination();
expectDestination(gatekeeperTakeLoginDestination(), 'index.php?f=main');
echo "Gatekeeper management login destination checks passed.\n";
