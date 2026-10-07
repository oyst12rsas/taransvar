<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/../script/gatewayAppLinkCommon.php';
require_once __DIR__.'/../dbfunc.php';
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Frame-Options: DENY');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$error=''; $row=null; $admin=false; $decided=false; $links=[]; $id='';
function appLinkEscape(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
try {
    $cfg=unitLinkConfig(); unitLinkTransport($cfg);
    // Reuse Gatekeeper's PHP session, with its current administrator checked in DB.
    session_start();
    $_SESSION['gateway_app_csrf'] ??= bin2hex(random_bytes(32));
    $db=getConnection(); $admin=gatewayAppAdmin($db,(int)($_SESSION['userid'] ?? 0));
    $id=(string)($_GET['request'] ?? '');
    if ($id!=='' && !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new UnitLinkException('Open the approval link from your TaraSec app.');
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
        if (!$admin || !hash_equals($_SESSION['gateway_app_csrf'],(string)($_POST['csrf'] ?? ''))) throw new UnitLinkException('Administrator login or approval session expired.');
        $decision=(string)($_POST['decision'] ?? '');
        if ($decision==='revoke') {
            $q=unitLinkQuery($db,'SELECT subjectHash,clientHash FROM gatewayAppLink WHERE linkId=? AND gatewayId=? AND provider=?',[(int)($_POST['link_id'] ?? 0),$cfg['gateway_id'],taraAccountServices()['identity_api_base']]);
            $link=$q->get_result()->fetch_assoc(); $q->close();
            if (!$link) throw new UnitLinkException('App link not found.');
            gatewayAppUnlink($db,$link['subjectHash'],$link['clientHash']); $decided=true;
        } elseif (!in_array($decision,['approve','reject'],true)) throw new UnitLinkException('Choose approve or reject.');
        else { gatewayAppDecide($db,$cfg,$id,(int)$_SESSION['userid'],$decision==='approve'); $decided=true; }
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '')!=='GET') throw new UnitLinkException('Use GET or POST.');
    if ($admin && $id==='') {
        $q=unitLinkQuery($db,"SELECT linkId,LEFT(clientHash,12) appId FROM gatewayAppLink WHERE gatewayId=? AND provider=? AND active=b'1' ORDER BY linkId",[$cfg['gateway_id'],taraAccountServices()['identity_api_base']]);
        $links=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
    }
    if (!$decided && $admin && $id!=='') {
        $q=unitLinkQuery($db,"SELECT confirmationCode FROM gatewayAppRequest WHERE requestId=? AND gatewayId=? AND provider=? AND decision='pending' AND expiresAt>NOW()",[$id,$cfg['gateway_id'],taraAccountServices()['identity_api_base']]);
        $row=$q->get_result()->fetch_assoc(); $q->close();
        if (!$row) throw new UnitLinkException('App-link request expired, changed or already decided.');
    }
} catch (Throwable $e) { http_response_code(400); $error=$e instanceof UnitLinkException?$e->getMessage():'App linking is unavailable. Check configuration and schema.'; }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Approve TaraSec app</title></head><body><main>
<h1>Link the phone app to <?=appLinkEscape(gethostname() ?: 'this gateway')?></h1>
<?php if ($error): ?><p role="alert"><?=appLinkEscape($error)?></p>
<?php elseif ($decided): ?><p>Decision saved. Return to the phone app and select Finish linking.</p>
<?php elseif (!$admin): ?><p>Sign in as a gateway administrator, then return to this approval page.</p>
<p><a href="index.php?f=login" target="_blank" rel="noopener">Open gateway administrator login</a></p><p><a href="">I've signed in — check again</a></p>
<?php elseif ($id===''): ?><h2>Approved app links</h2>
<?php if (!$links): ?><p>No active app links.</p><?php endif; ?>
<?php foreach ($links as $link): ?><form method="post"><p>App <?=appLinkEscape($link['appId'])?> · Link <?=appLinkEscape((string)$link['linkId'])?></p><input type="hidden" name="csrf" value="<?=appLinkEscape($_SESSION['gateway_app_csrf'])?>"><input type="hidden" name="link_id" value="<?=appLinkEscape((string)$link['linkId'])?>"><button name="decision" value="revoke">Revoke app access</button></form><?php endforeach; ?>
<?php elseif ($row): ?><p>Confirm that the code shown in your phone app is <strong><?=appLinkEscape($row['confirmationCode'])?></strong>.</p>
<p>This approves that app's account for read-only access to this gateway. Management access requires its separate approval.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=appLinkEscape($_SESSION['gateway_app_csrf'])?>"><button name="decision" value="approve">Approve phone app</button> <button name="decision" value="reject">Reject</button></form>
<?php endif; ?><?php if ($admin): ?><p><a href="appLink.php">Approved app links</a></p><?php endif; ?></main></body></html>
