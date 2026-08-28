<?php
declare(strict_types=1);
session_start();
header('Cache-Control: no-store');

require_once __DIR__ . '/basics.php';

function h($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}

$db = new CDb();
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$setup = $db->fetch('select inet_ntoa(internalIP) as internalIP from setup limit 1', array());
$gatewayIp = ($setup && !empty($setup['internalIP'])) ? (string)$setup['internalIP'] : '192.168.50.1';
$gp = explode('.', $gatewayIp);
$cp = explode('.', $ip);
if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || count($gp) !== 4 || count($cp) !== 4 || array_slice($gp,0,3) !== array_slice($cp,0,3)) {
    http_response_code(403);
    exit('Hotspot clients only');
}

if (empty($_SESSION['ts_logout_csrf'])) $_SESSION['ts_logout_csrf']=bin2hex(random_bytes(32));
$msg='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['logout'])) {
    $csrf=(string)($_POST['csrf'] ?? '');
    if (!$csrf || !hash_equals((string)$_SESSION['ts_logout_csrf'],$csrf)) {
        http_response_code(400); exit('Invalid request');
    }
    $cmd='sudo /usr/local/sbin/tarasec-subscriber-logout '.escapeshellarg($ip).' 2>&1';
    exec($cmd,$out,$rc);
    if ($rc!==0) { http_response_code(500); $msg='Unable to log out. Please try again.'; }
    else {
        $_SESSION=[]; session_destroy();
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="1;url=http://neverssl.com/"><title>TaraSec WiFi</title><style>body{font-family:Arial;background:#eef3f8;margin:0}.card{max-width:560px;margin:40px auto;background:white;padding:24px;border-radius:14px}.ok{color:#168b4a}</style></head><body><div class="card"><h2 class="ok">Logged out</h2><p>Your TaraSec hotspot session has been closed. The captive portal will appear again when you continue browsing.</p></div></body></html>';
        exit;
    }
}

$row=$db->fetch('SELECT s.username,h.subscriptionType,h.expiryTime,h.quotaMB,COALESCE(h.usageMB,0) usageMB FROM session s LEFT JOIN hotspotSubscriber h ON h.username=s.username WHERE s.ip=:ip AND s.active=1 ORDER BY s.sessionid DESC LIMIT 1', array(':ip'=>$ip), PDO::FETCH_ASSOC);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TaraSec WiFi</title><style>*{box-sizing:border-box}body{margin:0;font-family:Arial;background:#eef3f8;color:#172233}.top{background:#17212d;color:white;padding:16px 20px;font-size:20px;font-weight:700}.card{max-width:620px;margin:28px auto;background:white;padding:24px;border-radius:14px;box-shadow:0 3px 14px #0002}.ok{color:#168b4a}.btn{width:100%;padding:13px;border:0;border-radius:8px;background:#a43737;color:white;font-size:16px;font-weight:700}.note{background:#eef7ff;padding:12px;border-left:4px solid #268bc7;margin:15px 0}</style></head><body><div class="top">TaraSec <span style="font-weight:400;font-size:13px">Hotspot</span></div><div class="card"><h2 class="ok">Internet access active</h2><?php if($row):?><p>Signed in as <b><?=h($row['username'])?></b>.</p><div class="note"><?php if($row['subscriptionType']==='expiry'):?>Access until <?=h($row['expiryTime'])?>.<?php elseif($row['subscriptionType']==='quota'):?>Used <?=h(round((float)$row['usageMB'],1))?> MB of <?=h(round((float)$row['quotaMB'],1))?> MB.<?php else:?>Subscription: <?=h($row['subscriptionType'])?>.<?php endif?></div><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['ts_logout_csrf'])?>"><button class="btn" name="logout" value="1">Log out of this hotspot</button></form><?php else:?><p>No active TaraSec subscriber session was found for this device.</p><?php endif?><?php if($msg):?><p><?=h($msg)?></p><?php endif?></div></body></html>
