<?php
declare(strict_types=1);
require_once __DIR__.'/unitLinkRequestCommon.php';

function gatewayAppClient(string $client): string {
    if (!preg_match('/^[a-f0-9]{32}$/D',$client)) throw new UnitLinkException('Invalid app identity.');
    return hash('sha256',$client);
}
function gatewayAppAdmin(mysqli $db,int $userId): bool {
    if ($userId<=0) return false;
    // Check current DB authorization, not a stale session isAdmin flag.
    $q=unitLinkQuery($db,'SELECT userId FROM user WHERE userId=? AND CAST(isAdmin AS UNSIGNED)=1',[$userId]);
    $ok=(bool)$q->get_result()->fetch_assoc(); $q->close(); return $ok;
}
function gatewayAppRequest(mysqli $db,array $cfg,string $subjectHash,string $clientHash): array {
    $provider=taraAccountServices()['identity_api_base'];
    $id=bin2hex(random_bytes(16)); $code=strtoupper(bin2hex(random_bytes(4)));
    if (!(int)$db->query("SELECT GET_LOCK('tarasec:gateway-app-create',3)")->fetch_row()[0]) throw new UnitLinkException('Try again shortly.');
    try {
        $q=unitLinkQuery($db,'SELECT COUNT(*) n FROM gatewayAppRequest WHERE subjectHash=? AND created>NOW()-INTERVAL 1 MINUTE',[$subjectHash]);
        $n=(int)$q->get_result()->fetch_assoc()['n']; $q->close();
        if ($n>=3) throw new UnitLinkException('Too many app-link requests. Retry in a minute.');
        $q=unitLinkQuery($db,"UPDATE gatewayAppRequest SET decision='rejected',decidedAt=NOW() WHERE subjectHash=? AND clientHash=? AND decision='pending'",[$subjectHash,$clientHash]); $q->close();
        $q=unitLinkQuery($db,'INSERT INTO gatewayAppRequest(requestId,gatewayId,provider,subjectHash,clientHash,confirmationCode,expiresAt) VALUES(?,?,?,?,?,?,NOW()+INTERVAL 10 MINUTE)',[$id,$cfg['gateway_id'],$provider,$subjectHash,$clientHash,$code]); $q->close();
    } finally { $db->query("SELECT RELEASE_LOCK('tarasec:gateway-app-create')"); }
    return ['request_id'=>$id,'confirmation_code'=>$code,'approval_url'=>$cfg['base_url'].'/gatekeeper/appLink.php?request='.$id,'expires_in'=>600];
}
function gatewayAppDecide(mysqli $db,array $cfg,string $id,int $admin,bool $approve): void {
    if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new UnitLinkException('Invalid app-link request.');
    $db->begin_transaction();
    try {
        // Serialize against administrator demotion while making the decision.
        $q=unitLinkQuery($db,'SELECT userId FROM user WHERE userId=? AND CAST(isAdmin AS UNSIGNED)=1 FOR UPDATE',[$admin]);
        $authorized=$q->get_result()->fetch_assoc(); $q->close();
        if (!$authorized) throw new UnitLinkException('Gateway administrator login required.');
        $q=unitLinkQuery($db,"SELECT * FROM gatewayAppRequest WHERE requestId=? AND decision='pending' AND expiresAt>NOW() FOR UPDATE",[$id]);
        $r=$q->get_result()->fetch_assoc(); $q->close();
        if (!$r || $r['gatewayId']!==$cfg['gateway_id'] || $r['provider']!==taraAccountServices()['identity_api_base']) throw new UnitLinkException('App-link request expired, changed or already decided.');
        if ($approve) {
            $q=unitLinkQuery($db,"INSERT INTO gatewayAppLink(gatewayId,provider,subjectHash,clientHash,approvedByUserId) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE gatewayId=VALUES(gatewayId),provider=VALUES(provider),active=b'1',approvedByUserId=VALUES(approvedByUserId),tokenHash=NULL,expiresAt=NULL",[$cfg['gateway_id'],$r['provider'],$r['subjectHash'],$r['clientHash'],$admin]); $q->close();
        }
        $q=unitLinkQuery($db,'UPDATE gatewayAppRequest SET decision=?,decidedAt=NOW(),approvedByUserId=? WHERE requestId=?',[$approve?'approved':'rejected',$admin,$id]); $q->close();
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}
function gatewayAppGrant(mysqli $db,array $cfg,string $hash,string $clientHash): ?string {
    $provider=taraAccountServices()['identity_api_base'];
    $db->begin_transaction();
    try {
        $q=unitLinkQuery($db,"SELECT linkId FROM gatewayAppLink WHERE subjectHash=? AND clientHash=? AND gatewayId=? AND provider=? AND active=b'1' FOR UPDATE",[$hash,$clientHash,$cfg['gateway_id'],$provider]);
        $r=$q->get_result()->fetch_assoc(); $q->close();
        if (!$r) { $db->rollback(); return null; }
        $token=bin2hex(random_bytes(32));
        $q=unitLinkQuery($db,'UPDATE gatewayAppLink SET tokenHash=?,expiresAt=NOW()+INTERVAL 90 DAY WHERE linkId=?',[hash('sha256',$token),$r['linkId']]); $q->close();
        $db->commit(); return $token;
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}

function gatewayAppUnlink(mysqli $db,string $hash,string $clientHash): void {
    $db->begin_transaction();
    try {
        // Lock requests before links, matching approval's order. An approval
        // concurrent with unlink must not activate a grant after revocation.
        $q=unitLinkQuery($db,'SELECT requestId FROM gatewayAppRequest WHERE subjectHash=? AND clientHash=? FOR UPDATE',[$hash,$clientHash]); $q->get_result()->free(); $q->close();
        $q=unitLinkQuery($db,"UPDATE gatewayAppRequest SET decision='rejected',decidedAt=NOW() WHERE subjectHash=? AND clientHash=? AND decision='pending'",[$hash,$clientHash]); $q->close();
        $q=unitLinkQuery($db,"UPDATE gatewayAppLink SET active=b'0',tokenHash=NULL WHERE subjectHash=? AND clientHash=?",[$hash,$clientHash]); $q->close();
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}
