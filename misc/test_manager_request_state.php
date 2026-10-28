<?php
require __DIR__ . '/../html/managerRequestState.php';
date_default_timezone_set('UTC');
$now = strtotime('2026-10-28 12:00:00');
$base = [
    'managerRequestId' => 8, 'email' => 'test@example.invalid',
    'emailVerifiedTime' => '2026-10-07 15:05:02',
    'gatewayApprovedTime' => '2026-10-07 15:05:13',
    'credentialHash' => 'synthetic-hash', 'active' => "\x01",
    'expires' => '2026-10-29 12:00:00',
];
$cases = [
    'active binary bit' => [[], 'active', true],
    'active numeric bit' => [['active' => '1'], 'active', true],
    'expired formerly active grant' => [['expires' => '2026-10-14 14:26:32'], 'expired', false],
    'exact expiry boundary' => [['expires' => '2026-10-28 12:00:00'], 'expired', false],
    'rejected' => [['rejectedTime' => '2026-10-28 11:00:00'], 'rejected', false],
    'revoked' => [['active' => '0'], 'revoked', false],
    'revoked binary bit' => [['active' => "\x00"], 'revoked', false],
    'email pending' => [['emailVerifiedTime' => null, 'active' => '0'], 'email_pending', false],
    'admin pending' => [['gatewayApprovedTime' => null, 'active' => '0'], 'admin_pending', false],
    'credential pending' => [['credentialHash' => null, 'active' => '0'], 'credential_pending', false],
    'missing expiry allowed' => [['expires' => null], 'active', true],
    'invalid expiry fails closed' => [['expires' => 'invalid'], 'expired', false],
];
foreach ($cases as $label => [$changes, $state, $active]) {
    $actual = tarasecManagerRequestState(array_replace($base, $changes), $now);
    if ($actual['state'] !== $state || $actual['active'] !== $active) {
        throw new RuntimeException("Failed: $label");
    }
}
echo count($cases) . " manager request state cases passed\n";
