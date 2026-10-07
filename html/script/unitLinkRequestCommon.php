<?php
declare(strict_types=1);
require_once __DIR__.'/unitLinkCommon.php';

function unitLinkQuery(mysqli $db,string $sql,array $values=[]): mysqli_stmt {
    $q=$db->prepare($sql);
    if ($values) $q->bind_param(str_repeat('s',count($values)),...$values);
    $q->execute(); return $q;
}
function unitPollToken(string $id,array $cfg): string {
    if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new UnitLinkException('Invalid node request.');
    return hash_hmac('sha256','unit-poll:'.$cfg['gateway_id'].':'.$id,hex2bin($cfg['subject_key']));
}
function unitBrokerCall(string $provider,array $body): array {
    $provider=taraServiceBase($provider);
    $ch=curl_init($provider.'/unit-request.php');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($body),CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    $r=is_string($raw) && strlen($raw)<=32768 ? json_decode($raw,true) : null;
    if (!is_array($r) || ($r['ok'] ?? false)!==true || !in_array($status,[200,201],true)
        || ($r['gateway_id'] ?? '')!==$body['gateway_id'] || ($r['request_id'] ?? '')!==$body['request_id'])
        throw new UnitLinkException('Account-service linking is unavailable. Retry shortly.');
    return $r;
}
function unitApprovalUrl(string $url,string $provider,string $id): string {
    $u=parse_url($url); $p=parse_url($provider);
    if (!$u || ($u['scheme'] ?? '')!=='https' || strtolower($u['host'] ?? '')!==strtolower($p['host'] ?? '')
        || ($u['port'] ?? 443)!==($p['port'] ?? 443) || isset($u['user']) || isset($u['pass'])
        || ($u['path'] ?? '')!==rtrim($p['path'] ?? '','/').'/unit-approve.php'
        || ($u['query'] ?? '')!=='request='.$id || !preg_match('/^key=[a-f0-9]{64}$/D',$u['fragment'] ?? ''))
        throw new UnitLinkException('Account service returned an unexpected approval address.');
    return $url;
}
function unitCreateRequest(mysqli $db,array $cfg,array $unit,string $peer): string {
    $provider=taraAccountServices()['identity_api_base'];
    $id=bin2hex(random_bytes(16)); $unitId=(int)$unit['unitId'];
    $db->begin_transaction();
    try {
        // Serialize creation against the same unit, including parallel browsers.
        $q=unitLinkQuery($db,'SELECT unitId FROM unit WHERE unitId=? FOR UPDATE',[$unitId]); $q->close();
        $q=unitLinkQuery($db,"SELECT COUNT(*) n FROM unitLinkRequest WHERE unitId=? AND created>NOW()-INTERVAL 1 MINUTE",[$unitId]);
        $n=(int)$q->get_result()->fetch_assoc()['n']; $q->close();
        if ($n>=3) throw new UnitLinkException('Too many node-link requests. Retry in a minute.');
        // One outstanding request per unit; replacing it cancels older local requests.
        $q=unitLinkQuery($db,"UPDATE unitLinkRequest SET state='cancelled' WHERE unitId=? AND state='pending'",[$unitId]); $q->close();
        $q=unitLinkQuery($db,'INSERT INTO unitLinkRequest(requestId,unitId,ownerBinding,peer,gatewayId,provider,expiresAt) VALUES(?,?,?,?,?,?,NOW()+INTERVAL 10 MINUTE)',
            [$id,$unitId,(string)($unit['ownerId'] ?? ''),$peer,$cfg['gateway_id'],$provider]); $q->close();
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
    try {
        $r=unitBrokerCall($provider,['action'=>'create','request_id'=>$id,'gateway_id'=>$cfg['gateway_id'],
            'poll_hash'=>hash('sha256',unitPollToken($id,$cfg)),'node_label'=>substr($unit['hostname'] ?: 'Unit '.$unitId,0,100)]);
        return unitApprovalUrl((string)($r['approval_url'] ?? ''),$provider,$id);
    } catch (Throwable $e) {
        $q=unitLinkQuery($db,"UPDATE unitLinkRequest SET state='cancelled' WHERE requestId=?",[$id]); $q->close(); throw $e;
    }
}
function unitRequestBindingValid(array $r,array $cfg,array $unit,string $provider): bool {
    return $r['gatewayId']===$cfg['gateway_id'] && $r['provider']===$provider
        && (int)$r['unitId']===(int)$unit['unitId'] && $r['ownerBinding']===(string)($unit['ownerId'] ?? '')
        && $r['peer']===($unit['peer'] ?? '');
}
function unitApplyApproval(mysqli $db,array $r,array $cfg,string $subject): bool {
    $db->begin_transaction();
    try {
        $q=unitLinkQuery($db,"SELECT * FROM unitLinkRequest WHERE requestId=? AND state='pending' AND expiresAt>NOW() FOR UPDATE",[$r['requestId']]);
        $current=$q->get_result()->fetch_assoc(); $q->close();
        if (!$current) { $db->rollback(); return false; }
        $q=unitLinkQuery($db,"SELECT unitId,ownerId,INET_NTOA(ipAddress) peer FROM unit WHERE ipAddress=INET_ATON(?) AND lastSeen>NOW()-INTERVAL 15 MINUTE LIMIT 2 FOR UPDATE",[$current['peer']]);
        $units=$q->get_result()->fetch_all(MYSQLI_ASSOC); $unit=count($units)===1?$units[0]:null; $q->close();
        if (!$unit || !unitRequestBindingValid($current,$cfg,$unit,taraAccountServices()['identity_api_base'])) {
            $q=unitLinkQuery($db,"UPDATE unitLinkRequest SET state='cancelled' WHERE requestId=?",[$r['requestId']]); $q->close(); $db->commit(); return false;
        }
        $hash=unitSubjectHash($subject,$cfg);
        $q=unitLinkQuery($db,"INSERT INTO unitGoogleLink(unitId,subjectHash) VALUES(?,?) ON DUPLICATE KEY UPDATE active=b'1'",[$current['unitId'],$hash]); $q->close();
        $q=unitLinkQuery($db,"UPDATE unitLinkRequest SET state='applied' WHERE requestId=?",[$r['requestId']]); $q->close();
        // Persist apply and progress together BEFORE acknowledgement. Never reapply.
        $db->commit(); return true;
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}
