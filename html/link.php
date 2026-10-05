<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/script/unitLinkCommon.php';
require_once __DIR__.'/dbfunc.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$error=''; $qr=''; $payload=''; $unit=null;
try {
    $cfg=unitLinkConfig();
    // A plain-IP home page may send the browser to the configured HTTPS origin.
    // No credential is created or transmitted until HTTPS is established.
    if (strtolower((string)($_SERVER['HTTPS'] ?? ''))!=='on' && (string)($_SERVER['HTTPS'] ?? '')!=='1') {
        header('Location: '.$cfg['base_url'].'/link.php',true,303); exit;
    }
    unitLinkHttps();
    session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']); session_start();
    $_SESSION['unit_pair_csrf'] ??= bin2hex(random_bytes(32));
    $db=getConnection(); $peer=unitLocalPeer(); $unit=unitAtLocalPeer($db,$peer);
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
        if (!hash_equals($_SESSION['unit_pair_csrf'],(string)($_POST['csrf'] ?? ''))) throw new UnitLinkException('Reload this page and try again.');
        if (!is_executable('/usr/bin/qrencode')) throw new UnitLinkException('The gateway operator needs to install QR linking support.');
        $code=bin2hex(random_bytes(32));
        $payload=json_encode(['type'=>'tarasec-unit-pair','version'=>1,'gateway'=>$cfg['base_url'],'gateway_id'=>$cfg['gateway_id'],'code'=>$code,'unit_name'=>$unit['hostname'],'expires_at'=>time()+300],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        // Local renderer: the pairing code is never sent to a third-party QR service.
        $process=proc_open(['/usr/bin/qrencode','-t','SVG','-o','-','-m','4','-s','6'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if (!is_resource($process)) throw new UnitLinkException('Unable to generate the QR code.');
        fwrite($pipes[0],$payload); fclose($pipes[0]); $svg=stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process)!==0 || !$svg) throw new UnitLinkException('Unable to generate the QR code.');
        $unitId=(int)$unit['unitId']; $owner=$unit['ownerId']; $hash=hash('sha256',$code);
        $db->begin_transaction();
        // Serialize issuance per unit and bound repeated creation.
        $stmt=$db->prepare('SELECT unitId FROM unit WHERE unitId=? FOR UPDATE'); $stmt->bind_param('i',$unitId); $stmt->execute(); $stmt->get_result()->fetch_assoc(); $stmt->close();
        $stmt=$db->prepare('SELECT COUNT(*) n FROM unitPairCode WHERE unitId=? AND created>NOW()-INTERVAL 5 MINUTE');
        $stmt->bind_param('i',$unitId); $stmt->execute(); $count=(int)$stmt->get_result()->fetch_assoc()['n']; $stmt->close();
        if ($count>=5) throw new UnitLinkException('Too many codes requested. Wait five minutes and retry.');
        $stmt=$db->prepare('INSERT INTO unitPairCode(codeHash,unitId,ownerId,peerIp,expires) VALUES(?,?,?,INET_ATON(?),NOW()+INTERVAL 5 MINUTE)');
        $stmt->bind_param('siis',$hash,$unitId,$owner,$peer); $stmt->execute(); $stmt->close(); $db->commit();
        $qr='data:image/svg+xml;base64,'.base64_encode($svg);
    }
} catch (Throwable $e) {
    if (isset($db)) { try { $db->rollback(); } catch (Throwable $ignored) {} }
    $error=$e instanceof UnitLinkException ? $e->getMessage() : 'Unit linking is not ready. Ask the gateway operator to run the linking setup.';
}
function linkEscape(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Link to my app · TaraSec</title>
<style>body{font:18px system-ui;background:#eef4f7;color:#183747;margin:0;padding:28px}main{max-width:620px;margin:auto;background:white;padding:28px;border-radius:16px}button{background:#136c65;color:white;padding:14px 22px;border:0;border-radius:8px;font:inherit;cursor:pointer}img{max-width:100%;width:340px}.error{background:#fff1cb;padding:16px}textarea{width:100%;height:100px}small{display:block;margin-top:20px}</style></head><body><main>
<h1>Link to my app</h1>
<p>Open this page on the laptop you want to add. On your phone, open TaraSec → My units → Scan QR code.</p>
<?php if ($error!==''): ?><p class="error"><?=linkEscape($error)?></p><p>If this laptop uses NetBird, use the gateway's local address and make sure the connection goes through its hotspot LAN. The gateway must identify this laptop, rather than an overlay address.</p>
<?php elseif ($unit): ?><h2><?=linkEscape($unit['hostname'] ?: 'This laptop')?></h2><p>Connected through <?=linkEscape($cfg['base_url'])?>. Confirm that this is the laptop you want to add.</p>
<?php if ($qr!==''): ?><img src="<?=linkEscape($qr)?>" alt="Single-use TaraSec unit linking QR code"><p>This code expires in five minutes and works once. Keep it private.</p><details><summary>Can't scan? Copy pairing code</summary><textarea readonly><?=linkEscape($payload)?></textarea></details><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=linkEscape($_SESSION['unit_pair_csrf'])?>"><button type="submit"><?=$qr ? 'Create a new QR code' : 'Show QR code'?></button></form>
<?php endif; ?>
<small>This adds read-only status for this laptop and remembers its gateway. Gateway manager access requires a separate request and administrator approval.</small><p><a href="/gatekeeper/">Back to home</a></p>
</main></body></html>
