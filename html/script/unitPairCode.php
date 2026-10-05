<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/unitLinkCommon.php';
require_once __DIR__.'/../dbfunc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function codeReply(int $status,array $data): never {
    http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES); exit;
}
try {
    unitLinkHttps(); $cfg=unitGatewayConfig();
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') codeReply(405,['ok'=>false,'error'=>'post_required']);
    $code=(string)($_POST['code'] ?? ''); $client=(string)($_POST['client_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/D',$code) || !preg_match('/^[a-f0-9]{32}$/D',$client))
        codeReply(400,['ok'=>false,'error'=>'invalid_pairing_code']);
    // Bind the grant to the signed-in app account. Never accept a supplied Google subject.
    $subject=unitRedeemIdentity((string)($_POST['ticket'] ?? ''),$cfg);
    $db=getConnection(); $db->begin_transaction(); $hash=hash('sha256',$code);
    $stmt=$db->prepare('SELECT c.unitId,c.ownerId,c.peerIp,u.ownerId currentOwner,u.ipAddress,COALESCE(u.hostname,\'\') hostname FROM unitPairCode c JOIN unit u ON u.unitId=c.unitId WHERE c.codeHash=? AND c.consumed IS NULL AND c.expires>NOW() FOR UPDATE');
    $stmt->bind_param('s',$hash); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row || $row['ownerId']!==$row['currentOwner'] || $row['peerIp']!==$row['ipAddress']) {
        $db->rollback(); codeReply(410,['ok'=>false,'error'=>'pairing_expired_or_used']);
    }
    $unitId=(int)$row['unitId'];
    $stmt=$db->prepare("INSERT INTO unitGoogleLink(unitId,subjectHash) VALUES(?,?) ON DUPLICATE KEY UPDATE active=b'1'");
    $stmt->bind_param('is',$unitId,$subject); $stmt->execute(); $stmt->close();
    $stmt=$db->prepare('SELECT linkId FROM unitGoogleLink WHERE unitId=? AND subjectHash=? FOR UPDATE');
    $stmt->bind_param('is',$unitId,$subject); $stmt->execute(); $linkId=(int)$stmt->get_result()->fetch_assoc()['linkId']; $stmt->close();
    $clientHash=hash('sha256',$client);
    $stmt=$db->prepare("UPDATE unitAppToken t JOIN unitGoogleGrant g ON g.unitAppTokenId=t.unitAppTokenId SET t.active=b'0' WHERE g.linkId=? AND g.clientHash=?");
    $stmt->bind_param('is',$linkId,$clientHash); $stmt->execute(); $stmt->close();
    $token=bin2hex(random_bytes(32)); $tokenHash=hash('sha256',$token); $label='QR-linked app';
    $stmt=$db->prepare('INSERT INTO unitAppToken(unitId,tokenHash,label,expires) VALUES(?,?,?,NOW()+INTERVAL 90 DAY)');
    $stmt->bind_param('iss',$unitId,$tokenHash,$label); $stmt->execute(); $tokenId=(int)$stmt->insert_id; $stmt->close();
    $stmt=$db->prepare('INSERT INTO unitGoogleGrant(linkId,clientHash,unitAppTokenId) VALUES(?,?,?) ON DUPLICATE KEY UPDATE unitAppTokenId=VALUES(unitAppTokenId)');
    $stmt->bind_param('isi',$linkId,$clientHash,$tokenId); $stmt->execute(); $stmt->close();
    $stmt=$db->prepare('UPDATE unitPairCode SET consumed=NOW() WHERE codeHash=? AND consumed IS NULL');
    $stmt->bind_param('s',$hash); $stmt->execute(); $stmt->close();
    $db->commit();
    codeReply(201,['ok'=>true,'gateway_id'=>$cfg['gateway_id'],'scope'=>'single_unit_read_only','token'=>$token,'unit'=>['unitId'=>$unitId,'ownerId'=>$row['ownerId'],'hostname'=>$row['hostname']]]);
} catch (Throwable $e) {
    if (isset($db)) { try { $db->rollback(); } catch (Throwable $ignored) {} }
    error_log('QR unit pairing unavailable');
    codeReply(503,['ok'=>false,'error'=>'unit_pairing_unavailable']);
}
