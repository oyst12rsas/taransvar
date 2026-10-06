<?php
function appDemo5() {
    $esc=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    echo '<h1>Demo 5 — partner loses tagging</h1><p>A connected subnode hits a configured honeypot or reporting firewall rule. Normal receiver reports reach the DB; repeated untagged evidence after partner notification can issue a temporary source restriction.</p><p>Run the exercise in the TaraSec app. The app keeps polling the DB for evidence, notification, tagging status, distribution and receiver acknowledgements.</p><p><a href="https://tarasec.org/demo5/">Full explanation and operator setup</a> · <a href="index.php?f=appDemos">All demos</a></p>';
    try {
        $db=getConnection();
        $role=$db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) central FROM setup LIMIT 1')->fetch_assoc();
        if(empty($role['central'])) {echo '<p>Open this observer on the DB server.</p>';return;}
        $rows=$db->query("SELECT INET_NTOA(s.sourceIp) source,INET_NTOA(r.ip) gateway,s.state,s.firstReportAt,s.notifiedAt,s.restrictionUntil,s.sessionId,s.pauseState,s.pauseMessage,
            COUNT(d.receiverIp) expected,SUM(d.state='applied') applied
            FROM demo5Session s JOIN partnerRouter r ON r.routerId=s.routerId
            LEFT JOIN partnerRestrictionDelivery d ON d.sessionId=s.sessionId
            WHERE s.created>DATE_SUB(NOW(),INTERVAL 1 DAY)
            GROUP BY s.sessionId,s.sourceIp,r.ip,s.state,s.firstReportAt,s.notifiedAt,s.restrictionUntil,s.sessionId,s.pauseState,s.pauseMessage
            ORDER BY s.created DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);
        echo '<p>Snapshot at '.$esc(gmdate('c')).'. Refresh for current state. Expired restrictions are inactive even if historical state says blacklisted.</p><table><tr><th>Gateway / source</th><th>State</th><th>Tagging pause</th><th>Observation / ratio</th><th>Report</th><th>Notified</th><th>Restriction expiry</th><th>Applied / selected</th></tr>';
        require_once __DIR__.'/../../script/partnerObservationLib.php';
        foreach($rows as $r) {
            $q=$db->prepare('SELECT o.status,p.* FROM partnerObservation o LEFT JOIN partnerObservationSample p ON p.observationId=o.observationId AND p.checkedAt>DATE_SUB(NOW(),INTERVAL 90 SECOND) WHERE o.sessionId=?');
            $q->bind_param('s',$r['sessionId']);$q->execute();$samples=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            $assessment=partnerObservationDecision(array_filter($samples,fn($p)=>isset($p['receiverIp'])),partnerObservationConfig());
            $observation=($samples[0]['status']??'pending').' / '.($assessment['ratio']===null?'unknown':round($assessment['ratio']*100,1).'%');
            echo '<tr><td>'.$esc($r['gateway'].' / '.$r['source']).'</td><td>'.$esc($r['state']).'</td><td>'.$esc($r['pauseState'].' '.$r['pauseMessage']).'</td><td>'.$esc($observation).'</td><td>'.$esc($r['firstReportAt']).'</td><td>'.$esc($r['notifiedAt']).'</td><td>'.$esc($r['restrictionUntil']).'</td><td>'.$esc(($r['applied']??0).' / '.$r['expected']).'</td></tr>';
        }
        echo '</table>';$db->close();
    } catch(Throwable $e) {error_log('appDemo5 observer: '.$e->getMessage());echo '<p>Demo 5 unavailable; check migration and DB configuration.</p>';}
}

