<?php
// TaraSec subscriber logout. A deliberate logout is different from losing WiFi:
// it closes the TaraSec session/access first, then asks openNDS to deauthenticate.
error_reporting(E_ALL);
ini_set('display_errors', '0');

$clientIp = trim(isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');
if (filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || strpos($clientIp, '192.168.50.') !== 0) {
    http_response_code(403);
    echo 'This action is only available to a connected TaraSec hotspot client.';
    exit;
}

$cmd = 'sudo -n /usr/local/sbin/tarasec-captive-logout ' . escapeshellarg($clientIp);
exec($cmd, $output, $rc);
if ($rc !== 0) {
    error_log('TaraSec captive logout helper failed for ' . $clientIp . ' rc=' . $rc);
    http_response_code(500);
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><h2>Logout failed</h2><p>Please try again.</p>';
    exit;
}

header('Cache-Control: no-store');
header('Location: http://status.client/', true, 303);
exit;
