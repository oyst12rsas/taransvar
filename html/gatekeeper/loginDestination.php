<?php
// Only management approval routes may survive login; never accept a return URL.
function gatekeeperApprovalRequestId($value): ?int {
    if (!is_int($value) && !is_string($value)) return null;
    if (!preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value)) return null;
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>2147483647]]);
    return $id === false ? null : $id;
}

function gatekeeperRememberApprovalDestination(): void {
    if (($_GET['f'] ?? '') !== 'managerApprovals') return;
    $id = gatekeeperApprovalRequestId($_GET['requestId'] ?? null);
    $_SESSION['gatekeeper_login_destination'] = [
        'requestId'=>$id, 'until'=>time()+600,
    ];
}

function gatekeeperSafeLoginDestination($target): string {
    if ($target === 'index.php?f=managerApprovals') return $target;
    if (is_string($target) && preg_match('/^index\.php\?f=managerApprovals&requestId=([1-9][0-9]{0,9})$/D', $target, $m)
        && gatekeeperApprovalRequestId($m[1]) !== null) return $target;
    return 'index.php?f=main';
}

function gatekeeperTakeLoginDestination(): string {
    $saved = $_SESSION['gatekeeper_login_destination'] ?? null;
    unset($_SESSION['gatekeeper_login_destination']);
    if (!is_array($saved) || (int)($saved['until'] ?? 0) < time()) return 'index.php?f=main';
    $id = gatekeeperApprovalRequestId($saved['requestId'] ?? null);
    return 'index.php?f=managerApprovals'.($id === null ? '' : '&requestId='.$id);
}
