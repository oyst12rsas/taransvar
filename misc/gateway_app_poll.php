<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../html/script/gatewayAppLinkCommon.php';
require_once __DIR__.'/../html/script/nodePublicMetadata.php';
require_once __DIR__.'/../html/dbfunc.php';
function gatewayAccountCall(array $cfg,array $extra=[]): array {
    $provider=taraAccountServices()['identity_api_base'];
    $body=['action'=>'node_poll','service_node_id'=>unitServiceNodeId($cfg),'node_secret'=>unitServiceNodeSecret($cfg),'gateway_id'=>$cfg['gateway_id'],'node_label'=>substr(gethostname() ?: 'TaraSec node',0,100)]+$extra;
    $ch=curl_init($provider.'/app-node.php');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($body),CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    $r=is_string($raw) && strlen($raw)<131072?json_decode($raw,true):null;
    if ($status!==200 || !is_array($r) || ($r['ok'] ?? false)!==true || ($r['service_node_id'] ?? '')!==unitServiceNodeId($cfg)) throw new UnitLinkException('Hosted account service unavailable.');
    return $r;
}
function gatewayApplyHosted(mysqli $db,array $cfg,array $r): string {
    $id=(string)($r['requestId'] ?? ''); $subject=(string)($r['subject'] ?? ''); $client=(string)($r['clientHash'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/D',$id) || !preg_match('/^[a-f0-9]{64}$/D',$client) || !preg_match('/^[A-F0-9]{8}$/D',(string)($r['confirmationCode'] ?? ''))) throw new UnitLinkException('Invalid hosted request.');
    $hash=unitSubjectHash($subject,$cfg); $provider=taraAccountServices()['identity_api_base'];
    if ($r['state']==='revoking') { gatewayAppUnlink($db,$hash,$client); return 'revoked'; }
    $q=unitLinkQuery($db,'SELECT decision,subjectHash,clientHash,provider,gatewayId FROM gatewayAppRequest WHERE requestId=?',[$id]);
    $local=$q->get_result()->fetch_assoc(); $q->close();
    if ($local && ($local['subjectHash']!==$hash || $local['clientHash']!==$client || $local['provider']!==$provider || $local['gatewayId']!==$cfg['gateway_id'])) return 'rejected';
    if ($r['state']==='approved' || ($local && $local['decision']!=='pending')) {
        $q=unitLinkQuery($db,"SELECT linkId FROM gatewayAppLink WHERE subjectHash=? AND clientHash=? AND gatewayId=? AND provider=? AND active=b'1'",[$hash,$client,$cfg['gateway_id'],$provider]);
        $active=(bool)$q->get_result()->fetch_assoc(); $q->close();
        return $local && $local['decision']==='approved' && $active?'approved':'revoked';
    }
    if ($r['state']!=='decision') return 'rejected';
    $q=unitLinkQuery($db,'SELECT userId FROM user WHERE LOWER(username)=? AND CAST(isAdmin AS UNSIGNED)=1 LIMIT 2',[strtolower((string)($r['adminEmail'] ?? ''))]);
    $admins=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
    if (count($admins)!==1) return 'rejected';
    if (!$local) {
        $q=unitLinkQuery($db,'INSERT INTO gatewayAppRequest(requestId,gatewayId,provider,subjectHash,clientHash,confirmationCode,expiresAt) VALUES(?,?,?,?,?,?,?)',[$id,$cfg['gateway_id'],$provider,$hash,$client,$r['confirmationCode'],$r['expiresAt']]); $q->close();
    }
    try { gatewayAppDecide($db,$cfg,$id,(int)$admins[0]['userId'],true,(string)$r['adminEmail']); return 'approved'; }
    catch(UnitLinkException $e) { return 'rejected'; }
}
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $cfg=unitLinkConfig(); if ($cfg['mode']!=='hosted_gateway') exit;
        // Older worker installations have no publication directory yet.
        if (is_dir(dirname(TARA_NODE_METADATA))) nodePublicWrite($cfg);
        $db=getConnection(); if (!(int)$db->query("SELECT GET_LOCK('tarasec:gateway-app-worker',0)")->fetch_row()[0]) exit;
        try {
            $reply=gatewayAccountCall($cfg);
            foreach ($reply['requests'] as $r) {
                $result=gatewayApplyHosted($db,$cfg,$r);
                // Local decision is persisted before reporting: retries cannot restore revoked links.
                gatewayAccountCall($cfg,['report_id'=>$r['requestId'],'report_state'=>$result]);
            }
        } finally { $db->query("SELECT RELEASE_LOCK('tarasec:gateway-app-worker')"); $db->close(); }
    } catch(Throwable $e) { fwrite(STDERR,"Hosted app collection unavailable; check configuration and schema.\n"); exit(1); }
}
