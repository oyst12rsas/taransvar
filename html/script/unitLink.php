<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/unitLinkCommon.php';
require_once __DIR__.'/../dbfunc.php';
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff');
ini_set('session.use_strict_mode','1');
session_name('TaraSecUnitLink');
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'None']);
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$message=''; $success=false;
try {
    unitLinkHttps(); $cfg=unitLinkConfig();
    $db=getConnection(); $peer=unitLocalPeer(); $unit=unitAtLocalPeer($db,$peer);
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
        $cookie=(string)($_COOKIE['g_csrf_token'] ?? ''); $posted=(string)($_POST['g_csrf_token'] ?? '');
        if ($cookie==='' || !hash_equals($cookie,$posted) || ($_SESSION['unit_link_peer'] ?? '')!==$peer
            || (int)($_SESSION['unit_link_until'] ?? 0)<time()
            || (int)($_SESSION['unit_link_id'] ?? 0)!==(int)$unit['unitId'])
            throw new UnitLinkException('Linking session expired or changed. Reload this page on the unit.');
        $autoload=(string)($cfg['google_autoload'] ?? '');
        if (!is_readable($autoload)) throw new UnitLinkException('Google verification library is not installed on this gateway.');
        require_once $autoload;
        $client=new Google\Client(['client_id'=>$cfg['google_client_id']]);
        $credential=(string)($_POST['credential'] ?? '');
        if (strlen($credential)>16384) throw new UnitLinkException('Invalid Google credential.');
        $claims=$client->verifyIdToken($credential);
        if (!is_array($claims)) throw new UnitLinkException('Google sign-in could not be verified.');
        $subject=unitGoogleClaimsSubject($claims,$cfg,(string)($_SESSION['unit_link_nonce'] ?? ''));
        $hash=unitSubjectHash($subject,$cfg); $unitId=(int)$unit['unitId'];
        $stmt=$db->prepare("INSERT INTO unitGoogleLink(unitId,subjectHash) VALUES(?,?) ON DUPLICATE KEY UPDATE active=b'1'");
        $stmt->bind_param('is',$unitId,$hash); $stmt->execute(); $stmt->close();
        unset($_SESSION['unit_link_peer'],$_SESSION['unit_link_id'],$_SESSION['unit_link_nonce'],$_SESSION['unit_link_until']);
        $success=true;
        $message='This unit is linked. In the app, sign in with the same Google account, add this gateway and select Sync linked units.';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '')==='GET') {
        $_SESSION['unit_link_peer']=$peer; $_SESSION['unit_link_id']=(int)$unit['unitId'];
        $_SESSION['unit_link_nonce']=bin2hex(random_bytes(32)); $_SESSION['unit_link_until']=time()+600;
    } else { http_response_code(405); throw new UnitLinkException('Use GET or POST.'); }
    $db->close();
} catch (Throwable $e) {
    http_response_code(400);
    // Static application errors may be shown; never expose library/SQL details.
    $message=$e instanceof UnitLinkException
        ? $e->getMessage() : 'Unit linking is unavailable. Ask the gateway operator to check its configuration.';
    $cfg=null;
}
function unitLinkEscape(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Link this unit to TaraSec</title></head><body>
<main><h1>Link this unit to my app</h1>
<?php if ($message!==''): ?><p role="status"><?=unitLinkEscape($message)?></p><?php endif; ?>
<?php if ($cfg && !$success): ?>
<p>Unit: <?=unitLinkEscape($unit['hostname'] ?: 'Unit '.$unit['unitId'])?></p>
<p>Continue with the Google account used in your TaraSec app to grant access to this unit's threat information. This grants no gateway-manager access. On a shared computer, only link an account authorized to view this unit.</p>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<div id="g_id_onload" data-client_id="<?=unitLinkEscape($cfg['google_client_id'])?>" data-login_uri="<?=unitLinkEscape($cfg['base_url'].'/script/unitLink.php')?>" data-ux_mode="redirect" data-auto_prompt="false" data-nonce="<?=unitLinkEscape($_SESSION['unit_link_nonce'])?>"></div>
<div class="g_id_signin" data-type="standard" data-text="continue_with"></div>
<?php endif; ?>
<?php if ($cfg): ?><p>Gateway address for the app: <?=unitLinkEscape($cfg['base_url'])?></p><?php endif; ?>
<p>Your gateway stores a private, gateway-specific account hash alongside the unit link. Google identity is not added to shared threat reports.</p>
</main></body></html>
