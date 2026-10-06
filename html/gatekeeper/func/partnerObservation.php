<?php
function partnerObservation(): void {
    if (!isAdmin()) return;
    $db=getConnection();
    $central=$db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) central FROM setup LIMIT 1')->fetch_assoc();
    if (empty($central['central'])) {echo '<p>Open this page on the DB server.</p>';return;}
    echo '<h2>Partner observation</h2><p>Ratio: malicious rejected untagged connections / observed untagged connections. Unknown tags and policy denials are shown separately. Alarms require independent receivers and do not automatically restrict partners.</p>';
    try {
        $rows=$db->query('SELECT o.*,INET_NTOA(o.sourceIp) source,
            COALESCE(SUM(s.untagged),0) untagged,COALESCE(SUM(s.tagged),0) tagged,
            COALESCE(SUM(s.unknownTag),0) unknownTag,COALESCE(SUM(s.maliciousUntagged),0) malicious,
            COALESCE(SUM(s.policyDeniedUntagged),0) policyDenied,COUNT(s.receiverIp) receivers
            FROM partnerObservation o LEFT JOIN partnerObservationSample s ON s.observationId=o.observationId
            GROUP BY o.observationId ORDER BY o.observationId DESC LIMIT 50')->fetch_all(MYSQLI_ASSOC);
        echo '<table><tr><th>Partner source</th><th>Status</th><th>Untagged</th><th>Malicious</th><th>Ratio</th><th>Tagged</th><th>Unknown</th><th>Policy denials</th><th>Receivers</th><th>Notified</th><th>Expiry</th></tr>';
        foreach($rows as $r) {
            $ratio=(int)$r['untagged']?round(100*(int)$r['malicious']/(int)$r['untagged'],1).'%':'unknown';
            $dot=$r['status']==='alarm'?'red':'yellow';
            echo '<tr><td>'.htmlspecialchars($r['source']).'</td><td><img src="img/'.$dot.'_dot.png" alt="'.$dot.'"> '.htmlspecialchars($r['status']).'</td>';
            foreach([$r['untagged'],$r['malicious'],$ratio,$r['tagged'],$r['unknownTag'],$r['policyDenied'],$r['receivers'],$r['notifiedAt']??'pending',$r['expiresAt']] as $v) echo '<td>'.htmlspecialchars((string)$v).'</td>';
            echo '</tr>';
        }
        echo '</table>';
    } catch(Throwable $e) {echo '<p>Observation unavailable; apply the optional migration.</p>';}
    $db->close();
}
