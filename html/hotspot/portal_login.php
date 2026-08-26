<?php
// TaraSec captive-portal account login.
// This endpoint is for hotspot subscribers, not back-office login.
// The openNDS theme supplies the actual captive client IP explicitly.

declare(strict_types=1);
session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/basics.php';
require_once __DIR__ . '/genlib.php';
require_once __DIR__ . '/radiuslib.php';
require_once __DIR__ . '/funcs.php';
require_once __DIR__ . '/funcs2.php';

function portalReply(string $title, string $message, bool $success = false): never
{
    http_response_code($success ? 200 : 403);
    $titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $messageEsc = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $class = $success ? 'ok' : 'bad';
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>TaraSec hotspot</title><style>body{font-family:sans-serif;background:#f4f6f8;margin:0;padding:24px}.card{max-width:560px;margin:auto;background:white;padding:24px;border-radius:14px;box-shadow:0 2px 12px #0002}.ok{color:#087a35}.bad{color:#a51d1d}.btn{display:inline-block;margin-top:18px;padding:12px 18px;background:#222;color:#fff;text-decoration:none;border-radius:8px}</style></head><body><div class="card">';
    echo '<h2 class="'.$class.'">'.$titleEsc.'</h2><p>'.$messageEsc.'</p>';
    echo '<a class="btn" href="http://status.client">Return to hotspot access</a>';
    echo '</div></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    portalReply('Invalid request', 'Use the TaraSec captive portal to log in.');
}

$username = trim((string)($_POST['name'] ?? ''));
$password = (string)($_POST['pass'] ?? '');
$clientIp = trim((string)($_POST['client_ip'] ?? ''));

if ($username === '' || $password === '' || filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
    portalReply('Login failed', 'Missing or invalid hotspot login information.');
}

$db = new CDb();
$setup = $db->fetch('select inet_ntoa(internalIP) as internalIP from setup limit 1', array());
$gatewayIp = ($setup && !empty($setup['internalIP'])) ? (string)$setup['internalIP'] : '192.168.50.1';
$gatewayParts = explode('.', $gatewayIp);
$clientParts = explode('.', $clientIp);
if (count($gatewayParts) !== 4 || count($clientParts) !== 4 || array_slice($gatewayParts, 0, 3) !== array_slice($clientParts, 0, 3)) {
    portalReply('Login failed', 'This login request did not originate from a TaraSec hotspot client address.');
}

// Existing TaraSec databases contain both newer RADIUS-style rows
// (Cleartext-Password :=) and older generated hotspot users where the password
// is simply stored in value with op == and attribute blank.  Both are legitimate
// local hotspot account formats and use the same quota/expiry columns.
$user = $db->fetch(
    "select username,value,confirmedTime,subscriptionType,expirytime,giveHoursAfterLogin,mbquota,coalesce(mbusage,0) as mbusage,attribute,op
       from radcheck
      where username=:name
        and ((op=':=' and attribute='Cleartext-Password') or (op='==' and coalesce(attribute,'')=''))
      limit 1",
    array(':name' => $username),
    PDO::FETCH_ASSOC
);

if (!$user || !hash_equals((string)$user['value'], $password)) {
    portalReply('Login failed', 'Incorrect username or password.');
}

// Legacy generated quota users predate confirmation and have confirmedTime NULL.
// Newer Cleartext-Password accounts retain the confirmation requirement.
$isLegacy = ((string)$user['op'] === '==' && (string)$user['attribute'] === '');
if (!$isLegacy && empty($user['confirmedTime'])) {
    portalReply('Account not confirmed', 'This hotspot account must be confirmed before it can be used.');
}

$type = (string)$user['subscriptionType'];
if (($type === 'limited' || $type === 'expiry') && empty($user['expirytime'])) {
    $hours = (int)($user['giveHoursAfterLogin'] ?? 0);
    if ($hours > 0) {
        $db->execute(
            'update radcheck set expirytime=DATE_ADD(NOW(), INTERVAL :hours HOUR) where username=:name',
            array(':hours' => $hours, ':name' => $username)
        );
        $user = $db->fetch(
            'select subscriptionType,expirytime,mbquota,coalesce(mbusage,0) as mbusage from radcheck where username=:name limit 1',
            array(':name' => $username),
            PDO::FETCH_ASSOC
        );
        $type = (string)$user['subscriptionType'];
    }
}

$quotaOk = ((float)$user['mbusage'] < (float)$user['mbquota']);
$expiryOk = !empty($user['expirytime']) && strtotime((string)$user['expirytime']) > time();
$allowed = false;
if ($type === 'quota') {
    $allowed = $quotaOk;
} elseif ($type === 'expiry') {
    $allowed = $expiryOk;
} elseif ($type === 'limited') {
    $allowed = $quotaOk && $expiryOk;
}

if (!$allowed) {
    $db->execute('delete from access where ip=:ip', array(':ip' => $clientIp));
    portalReply('Access is not active', 'The username and password are correct, but this account has expired or has no remaining quota.');
}

// Create the subscriber session for the openNDS client address, never the
// management/browser REMOTE_ADDR, and grant access immediately.
$db->execute(
    'update session set active=0, logouttime=NOW() where ip=:ip and active=1 and logouttime is null',
    array(':ip' => $clientIp)
);
$db->execute(
    'insert into session (ip,username,logintime,lastrequest,active) values (:ip,:username,NOW(),NOW(),1)',
    array(':ip' => $clientIp, ':username' => $username)
);
$db->execute(
    'insert into access (ip,hasaccess,updated) values (:ip,1,NOW()) on duplicate key update hasaccess=1,updated=NOW()',
    array(':ip' => $clientIp)
);
$db->execute('update radcheck set last_login=NOW() where username=:name', array(':name' => $username));

portalReply('Access confirmed', 'Your account is valid. Return to the hotspot page to enable Internet access.', true);
