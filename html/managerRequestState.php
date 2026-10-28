<?php
// Shared request state for the app API and administrator approval page.
function tarasecManagerRequestState(array $row, ?int $now = null): array
{
    $now = $now ?? time();
    $expiry = $row['expires'] ?? null;
    $expiryTime = $expiry === null ? null : strtotime((string)$expiry);
    $expired = array_key_exists('requestExpired', $row)
        ? (int)$row['requestExpired'] === 1
        : $expiry !== null && ($expiryTime === false || $expiryTime <= $now);
    $email = !empty($row['emailVerifiedTime']);
    $approved = !empty($row['gatewayApprovedTime']);
    $credential = !empty($row['credentialHash']);
    $rejected = !empty($row['rejectedTime']);
    // mysqli can return a BIT column as a binary byte, rather than "1".
    $enabled = in_array($row['active'] ?? null, [true, 1, '1', "\x01"], true);
    $ready = $email && $approved && $credential;
    $state = match (true) {
        $expired => 'expired',
        $rejected => 'rejected',
        !$email => 'email_pending',
        !$approved => 'admin_pending',
        !$credential => 'credential_pending',
        !$enabled => 'revoked',
        default => 'active',
    };
    return [
        'requestId' => (int)($row['managerRequestId'] ?? 0),
        'email' => (string)($row['email'] ?? ''),
        'credentialReady' => $credential,
        'emailVerified' => $email,
        'gatewayApproved' => $approved,
        'rejected' => $rejected,
        'expired' => $expired,
        'expires' => $expiry,
        'state' => $state,
        'active' => !$expired && !$rejected && $ready && $enabled,
    ];
}
