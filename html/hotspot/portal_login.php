<?php
// TaraSec captive-portal account login.
// Subscriber accounts are TaraSec accounts; radcheck is only a legacy fallback
// while existing installations are migrated away from FreeRADIUS.
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
    if ($success && $fas !== '') {
        header('Location: http://status.client/opennds_preauth/?fas=' . rawurlencode($fas) . '&continue=clicked', true, 303);
        exit;
    }
    http_response_code($success ? 200 : 403);
    $titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $messageEsc = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $class = $success ? 'ok' : 'bad';
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TaraSec hotspot</title><style>body{font-family:sans-serif;background:#f4f6f8;margin:0;padding:24px}.card{max-width:560px;margin:auto;background:white;padding:24px;border-radius:14px;box-shadow:0 2px 12px #0002}.ok{color:#087a35}.bad{color:#a51d1d}.btn{display:inline-block;margin-top:18px;padding:12px 18px;background:#222;color:#fff;text-decoration:none;border-radius:8px}</style></head><body><div class="card">';
    echo '<h2 class="'.$class.'">'.$titleEsc.'</h2><p>'.$messageEsc.'</p><a class="btn" href="http://status.client">Return to hotspot access</a></div></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') portalReply('Invalid request', 'Use the TaraSec captive portal to log in.');
$username = trim((string)($_POST['name'] ?? ''));
$password = (string)($_POST['pass'] ?? '');
$postedClientIp = trim((string)($_POST['client_ip'] ?? ''));
$clientIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$fas = trim((string)($_POST['fas'] ?? ''));
if ($username === '' || $password === '' || filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) portalReply('Login failed', 'Missing or invalid hotspot login information.');
if ($postedClientIp !== '' && (!filter_var($postedClientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !hash_equals($clientIp, $postedClientIp))) portalReply('Login failed', 'The hotspot client address did not match this connection. Please reconnect and try again.');

$db = new CDb();
$setup = $db->fetch('select inet_ntoa(internalIP) as internalIP from setup limit 1', array());
$gatewayIp = ($setup && !empty($setup['internalIP'])) ? (string)$setup['internalIP'] : '192.168.50.1';
$gatewayParts = explode('.', $gatewayIp); $clientParts = explode('.', $clientIp);
if (count($gatewayParts) !== 4 || count($clientParts) !== 4 || array_slice($gatewayParts,0,3) !== array_slice($clientParts,0,3)) portalReply('Login failed', 'This login request did not originate from a TaraSec hotspot client address.');

// A back-office administrator identity is never valid as a captive-portal subscriber,
// even if an old FreeRADIUS installation contains a radcheck row with the same name.
try {
    $admin = $db->fetch('select userId from user where username=:name and cast(isAdmin as unsigned)=1 limit 1', array(':name'=>$username), PDO::FETCH_ASSOC);
    if ($admin) portalReply('Login failed', 'This is an administrator account, not a hotspot subscriber account.');
} catch (Throwable $e) { }

$user = null;
try {
    $user = $db->fetch("select username,password,confirmedTime,subscriptionType,expiryTime,giveHoursAfterLogin,quotaMB,coalesce(usageMB,0) usageMB,cast(enabled as unsigned) enabled from hotspotSubscriber where username=:name limit 1", array(':name'=>$username), PDO::FETCH_ASSOC);
} catch (Throwable $e) { $user = null; }

// Compatibility only: old installations may not have run migrate_accounts.sql yet.
// Do not create new radcheck records. Once migrated, hotspotSubscriber wins.
$legacy = false;
if (!$user) {
    try {
        $r = $db->fetch("select id,username,value,confirmedTime,subscriptionType,expirytime,giveHoursAfterLogin,mbquota,coalesce(mbusage,0) mbusage,attribute,op from radcheck where username=:name and ((op=':=' and attribute='Cleartext-Password') or (op='==' and coalesce(attribute,'')='')) limit 1", array(':name'=>$username), PDO::FETCH_ASSOC);
        if ($r) {
            $legacy = true;
            $user = array('username'=>$r['username'],'password'=>$r['value'],'confirmedTime'=>(((string)$r['op']==='==' && (string)$r['attribute']==='') ? 'legacy' : $r['confirmedTime']),'subscriptionType'=>$r['subscriptionType'],'expiryTime'=>$r['expirytime'],'giveHoursAfterLogin'=>$r['giveHoursAfterLogin'],'quotaMB'=>$r['mbquota'],'usageMB'=>$r['mbusage'],'enabled'=>1);
        }
    } catch (Throwable $e) { $user = null; }
}
if (!$user || !(int)$user['enabled'] || !hash_equals((string)$user['password'], $password)) portalReply('Login failed', 'Incorrect username or password.');
if (empty($user['confirmedTime'])) portalReply('Account not confirmed', 'This hotspot account must be confirmed before it can be used.');

$type=(string)$user['subscriptionType'];
if (($type==='limited'||$type==='expiry') && empty($user['expiryTime'])) {
    $hours=(int)($user['giveHoursAfterLogin']??0);
    if ($hours>0) {
        if ($legacy) $db->execute('update radcheck set expirytime=DATE_ADD(NOW(), INTERVAL '.intval($hours).' HOUR) where username=:name',array(':name'=>$username));
        else $db->execute('update hotspotSubscriber set expiryTime=DATE_ADD(NOW(), INTERVAL '.intval($hours).' HOUR) where username=:name',array(':name'=>$username));
        $user['expiryTime']=date('Y-m-d H:i:s',time()+$hours*3600);
    }
}
$quotaOk=((float)$user['usageMB'] < (float)$user['quotaMB']);
$expiryOk=!empty($user['expiryTime']) && strtotime((string)$user['expiryTime'])>time();
$allowed=($type==='quota'?$quotaOk:($type==='expiry'?$expiryOk:($type==='limited'?$quotaOk&&$expiryOk:false)));
if (!$allowed) { $db->execute('delete from access where ip=:ip',array(':ip'=>$clientIp)); portalReply('Access is not active','The account has expired or has no remaining quota.'); }

$db->execute('update session set active=0, logouttime=NOW() where ip=:ip and active=1 and logouttime is null',array(':ip'=>$clientIp));
$db->execute('insert into session (ip,username,logintime,lastrequest,active) values (:ip,:username,NOW(),NOW(),1)',array(':ip'=>$clientIp,':username'=>$username));
$db->execute('insert into access (ip,hasaccess,updated) values (:ip,1,NOW()) on duplicate key update hasaccess=1,updated=NOW()',array(':ip'=>$clientIp));
if ($legacy) $db->execute('update radcheck set last_login=NOW() where username=:name',array(':name'=>$username));
else $db->execute('update hotspotSubscriber set lastLogin=NOW() where username=:name',array(':name'=>$username));
portalReply('Access confirmed','Your account is valid. Continue to authorize Internet access.',true,$fas);
