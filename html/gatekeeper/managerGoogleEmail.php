<?php
// This proof is written only after Gatekeeper redeems a verified Google ticket.
function managerRecordGoogleIdentity(array &$session, int $userId, string $email): void {
    $session['gatekeeper_google_identity'] = [
        'userId'=>$userId, 'email'=>strtolower(trim($email)), 'verifiedAt'=>time(),
    ];
}

function managerVerifiedGoogleEmail(array $session, ?int $now = null): ?string {
    $proof = $session['gatekeeper_google_identity'] ?? null;
    $now ??= time();
    if (!is_array($proof) || empty($session['userid']) || empty($session['isAdmin'])
        || (int)($proof['userId'] ?? 0) !== (int)$session['userid']
        || !is_int($proof['verifiedAt'] ?? null) || $proof['verifiedAt'] > $now
        || $proof['verifiedAt'] < $now-1800
        || !is_string($proof['email'] ?? null)
        || !filter_var($proof['email'], FILTER_VALIDATE_EMAIL)) return null;
    return strtolower($proof['email']);
}

function managerConfirmGoogleEmail(mysqli $db, array $session, ?int $requestId = null): int {
    $email = managerVerifiedGoogleEmail($session);
    if ($email === null) return 0;
    $userId = (int)$session['userid'];
    $db->begin_transaction();
    try {
        // Do not rely on a stale administrator flag after demotion or suspension.
        $q = $db->prepare('SELECT userId FROM user WHERE userId=? AND LOWER(username)=? AND CAST(isAdmin AS UNSIGNED)=1 AND (suspendedUntil IS NULL OR suspendedUntil<=NOW()) FOR UPDATE');
        $q->bind_param('is', $userId, $email);
        $q->execute();
        $authorized = $q->get_result()->fetch_assoc();
        $q->close();
        if (!$authorized) { $db->rollback(); return 0; }
        // Confirm email only. Management approval and activation stay explicit.
        $sql = 'UPDATE managerRequest SET emailVerifiedTime=NOW(), emailVerifyTokenPlain=NULL, emailVerifyTokenHash=NULL WHERE LOWER(email)=? AND emailVerifiedTime IS NULL AND gatewayApprovedTime IS NULL AND rejectedTime IS NULL AND (expires IS NULL OR expires>NOW())';
        if ($requestId !== null) $sql .= ' AND managerRequestId=?';
        $q = $db->prepare($sql);
        if ($requestId === null) $q->bind_param('s', $email);
        else $q->bind_param('si', $email, $requestId);
        $q->execute();
        $count = $q->affected_rows;
        $q->close();
        $db->commit();
        return $count;
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}
