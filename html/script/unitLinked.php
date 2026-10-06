<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/unitLinkCommon.php';
require_once __DIR__.'/../dbfunc.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function linkedReply(int $code,array $data): never { http_response_code($code); echo json_encode($data,JSON_UNESCAPED_SLASHES); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    unitLinkHttps(); $cfg=unitLinkConfig();
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='GET') linkedReply(200,['ok'=>true,'gateway_id'=>$cfg['gateway_id'],'base_url'=>$cfg['base_url'],'link_path'=>'/script/unitLink.php','account_services'=>taraAccountServices()]);
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') linkedReply(405,['ok'=>false,'error'=>'post_required']);
    $hash=unitRedeemIdentity((string)($_POST['ticket'] ?? ''),$cfg);
    $action=(string)($_POST['action'] ?? 'list'); $db=getConnection();
    if ($action==='unlink') {
        $unitId=(int)($_POST['unit_id'] ?? 0);
        $db->begin_transaction();
        $stmt=$db->prepare('SELECT linkId FROM unitGoogleLink WHERE subjectHash=? AND unitId=? FOR UPDATE');
        $stmt->bind_param('si',$hash,$unitId); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) { $db->rollback(); linkedReply(404,['ok'=>false,'error'=>'unit_link_not_found']); }
        $linkId=(int)$row['linkId'];
        $stmt=$db->prepare("UPDATE unitGoogleLink SET active=b'0' WHERE linkId=?");
        $stmt->bind_param('i',$linkId); $stmt->execute(); $stmt->close();
        $stmt=$db->prepare("UPDATE unitAppToken t JOIN unitGoogleGrant g ON g.unitAppTokenId=t.unitAppTokenId SET t.active=b'0' WHERE g.linkId=?");
        $stmt->bind_param('i',$linkId); $stmt->execute(); $stmt->close();
        $db->commit(); $db->close(); linkedReply(200,['ok'=>true]);
    }
    if ($action!=='list') linkedReply(400,['ok'=>false,'error'=>'unsupported_action']);
    $client=(string)($_POST['client_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/D',$client)) linkedReply(400,['ok'=>false,'error'=>'invalid_client_id']);
    $clientHash=hash('sha256',$client);
    $db->begin_transaction();
    // Lock links so list rotation cannot race revocation or another app sync.
    $stmt=$db->prepare("SELECT l.linkId,u.unitId,u.ownerId,COALESCE(u.hostname,'') hostname,COALESCE(u.description,'') description FROM unitGoogleLink l JOIN unit u ON u.unitId=l.unitId WHERE l.subjectHash=? AND l.active=b'1' ORDER BY l.linkId FOR UPDATE");
    $stmt->bind_param('s',$hash); $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $units=[];
    foreach ($rows as $row) {
        $linkId=(int)$row['linkId']; $unitId=(int)$row['unitId'];
        $stmt=$db->prepare("UPDATE unitAppToken t JOIN unitGoogleGrant g ON g.unitAppTokenId=t.unitAppTokenId SET t.active=b'0' WHERE g.linkId=? AND g.clientHash=?");
        $stmt->bind_param('is',$linkId,$clientHash); $stmt->execute(); $stmt->close();
        $token=bin2hex(random_bytes(32)); $tokenHash=hash('sha256',$token); $label='Google-linked app';
        $stmt=$db->prepare('INSERT INTO unitAppToken(unitId,tokenHash,label,expires) VALUES(?,?,?,NOW()+INTERVAL 90 DAY)');
        $stmt->bind_param('iss',$unitId,$tokenHash,$label); $stmt->execute(); $tokenId=(int)$stmt->insert_id; $stmt->close();
        $stmt=$db->prepare('INSERT INTO unitGoogleGrant(linkId,clientHash,unitAppTokenId) VALUES(?,?,?) ON DUPLICATE KEY UPDATE unitAppTokenId=VALUES(unitAppTokenId)');
        $stmt->bind_param('isi',$linkId,$clientHash,$tokenId); $stmt->execute(); $stmt->close();
        $units[]=['unitId'=>$unitId,'ownerId'=>$row['ownerId'],'hostname'=>$row['hostname'],'description'=>$row['description'],'token'=>$token,'scope'=>'single_unit_read_only'];
    }
    $db->commit(); $db->close();
    linkedReply(200,['ok'=>true,'gateway_id'=>$cfg['gateway_id'],'units'=>$units,'server_time'=>gmdate('c')]);
} catch (Throwable $e) {
    if (isset($db)) { try { $db->rollback(); } catch (Throwable $ignored) {} }
    error_log('Unit account linking unavailable');
    linkedReply(503,['ok'=>false,'error'=>'unit_link_unavailable']);
}
