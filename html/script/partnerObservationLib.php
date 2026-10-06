<?php
declare(strict_types=1);
function partnerObservationConfig(): array {
    $p=defined('TARASEC_PARTNER_OBSERVATION_CONFIG')?TARASEC_PARTNER_OBSERVATION_CONFIG:'/etc/tarasec/partner-observation.php';
    $c=is_file($p)?require $p:[];
    return is_array($c)?$c:[];
}
function partnerObservationDecision(array $samples,array $config): array {
    $total=0; $malicious=0; $receivers=0;
    foreach ($samples as $s) {
        $u=(int)$s['untagged']; $m=(int)$s['maliciousUntagged'];
        if ($u<0 || $m<0 || $m>$u) throw new InvalidArgumentException('Invalid observation counts');
        $total+=$u; $malicious+=$m;
        if ($m>0) $receivers++;
    }
    $ratio=$total>0?$malicious/$total:null;
    $enough=$total>=max(1,(int)($config['minimum_connections']??20))
        && $malicious>=max(1,(int)($config['minimum_malicious']??5))
        && $receivers>=max(2,(int)($config['minimum_receivers']??2));
    return ['status'=>$enough && $ratio>=max(0.01,min(1.0,(float)($config['alarm_ratio']??0.5)))
        ?'alarm':'insufficient_evidence','untagged'=>$total,'malicious'=>$malicious,
        'ratio'=>$ratio,'receivers'=>$receivers];
}
function partnerObservationStart(mysqli $db,int $reportId,string $source,?int $tag,?int $at,int $severity,string $category,string $why): void {
    $c=partnerObservationConfig();
    // A source-policy denial is not evidence of malicious activity.
    if (empty($c['enabled']) || $tag!==0 || $at===null || $at<time()-60
        || $severity<7 || !in_array($category,['ssh_fail','iptables','attack_severity_7'],true)
        || stripos($why,'SSH_DENIED')!==false || stripos($why,'access policy')!==false) return;
    $central=$db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) central FROM setup LIMIT 1')->fetch_assoc();
    if (empty($central['central'])) return;
    // Exact registered partner address only; do not infer router vs NAT client.
    $duration=max(60,min(600,(int)($c['duration_seconds']??300)));
    $db->begin_transaction();
    try {
        $q=$db->prepare('SELECT routerId FROM partnerRouter WHERE ip=INET_ATON(?) LIMIT 1 FOR UPDATE');
        $q->bind_param('s',$source); $q->execute(); $r=$q->get_result()->fetch_assoc(); $q->close();
        if ($r) {
            $q=$db->prepare('UPDATE partnerObservation SET reportId=COALESCE(reportId,?) WHERE sourceIp=INET_ATON(?) AND expiresAt>NOW()');
            $q->bind_param('is',$reportId,$source);$q->execute();$q->close();
            $q=$db->prepare('INSERT INTO partnerObservation(routerId,sourceIp,reportId,expiresAt)
                SELECT ?,INET_ATON(?),?,DATE_ADD(NOW(),INTERVAL ? SECOND)
                WHERE NOT EXISTS(SELECT 1 FROM partnerObservation WHERE sourceIp=INET_ATON(?) AND expiresAt>NOW())');
            $q->bind_param('isiis',$r['routerId'],$source,$reportId,$duration,$source); $q->execute(); $q->close();
        }
        $db->commit();
    } catch(Throwable $e) {$db->rollback();throw $e;}
}
