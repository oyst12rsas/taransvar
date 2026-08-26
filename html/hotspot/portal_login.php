<?php
// TaraSec captive-portal account login.
// This endpoint is for hotspot subscribers, not back-office login.
// Client identity is derived from the TCP peer address. A posted client_ip is
// treated only as a consistency check and is never authoritative.

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/basics.php';
require_once __DIR__ . '/genlib.php';
require_once __DIR__ . '/radiuslib.php';
require_once __DIR__ . '/funcs.php';
require_once __DIR__ . '/funcs2.php';

function portalReply($title, $message, $success = false, $fas = '')
{
    http_response_code($success ? 200 : 403);
    $titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $messageEsc = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $class = $success ? 'ok' : 'bad';
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>TaraSec hotspot</title><style>body{font-family:sans-serif;background:#f4f6f8;margin:0;padding:24px}.card{max-width:560px;margin:auto;background:white;padding:24px;border-radius:14px;box-shadow:0 2px 12px #0002}.ok{color:#087a35}.bad{color:#a51d1d}.btn{display:inline-block;margin-top:18px;padding:12px 18px;background:#222;color:#fff;text-decoration:none;border-radius:8px}</style></head><body><div class="card">';
    echo '<h2 class="'.$class.'">'.$titleEsc.'</h2><p>'.$messageEsc.'</p>';
    if ($success && $fas !== '') {
        $href = 'http://status.client/opennds_preauth/?fas=' . rawurlencode($fas) . '&continue=clicked';
        echo '<a class="btn" href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">Continue to Internet access</a>';
    } else {
        echo '<a class="btn" href="http://status.client">Return to hotspot access</a>';
    }
    echo '</div></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    portalReply('Invalid request', 'Use the TaraSec captive portal to log in.');
}

$username = trim(isset($_POST['name']) ? (string)$_POST['name'] : '');
$password = isset($_POST['pass']) ? (string)$_POST['pass'] : '';
$postedClientIp = trim(isset($_POST['client_ip']) ? (string)$_POST['client_ip'] : '');
$clientIp = trim(isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');
$fas = trim(isset($_POST['fas']) ? (string)$_POST['fas'] : '');

if ($username === '' || $password === '' || filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
    portalReply('Login failed', 'Missing or invalid hotspot login information.');
}

// The hidden client_ip comes from the openNDS theme. It is useful for detecting
// proxying/tampering, but must never decide which client receives access.
if ($postedClientIp !== '' && (!filter_var($postedClientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !hash_equals($clientIp, $postedClientIp))) {
    error_log('TaraSec captive login client address mismatch: peer=' . $clientIp . ' posted=' . $postedClientIp);
    portalReply('Login failed', 'The hotspot client address did not match this connection. Please reconnect to the hotspot and try again.');
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
// is stored in value with op == and attribute blank.
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

$isLegacy = ((string)$user['op'] === '==' && (string)$user['attribute'] === '');
if (!$isLegacy && empty($user['confirmedTime'])) {
    portalReply('Account not confirmed', 'This hotspot account must be confirmed before it can be used.');
}

$type = (string)$user['subscriptionType'];
if (($type === 'limited' || $type === 'expiry') && empty($user['expirytime'])) {
    $hours = (int)(isset($user['giveHoursAfterLogin']) ? $user['giveHoursAfterLogin'] : 0);
    if ($hours > 0) {
        $db->execute(
            'update radcheck set expirytime=DATE_ADD(NOW(), INTERVAL '.intval($hours).' HOUR) where username=:name',
            array(':name' => $username)
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

portalReply('Access confirmed', 'Your account is valid. Continue to the TaraSec hotspot page to authorize Internet access.', true, $fas);
