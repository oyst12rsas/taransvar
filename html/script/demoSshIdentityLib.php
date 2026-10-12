<?php
declare(strict_types=1);

function demoSshVerifiedIdentity(mysqli $db, string $gateway, string $proof): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $proof))
        throw new InvalidArgumentException('Invalid gateway identity proof');
    // A fixed literal VPN peer and path; no caller-supplied URLs or redirects.
    $ip = ip2long($gateway);
    if ($ip === false || (($ip & 0xffc00000) !== ip2long('100.64.0.0')))
        throw new InvalidArgumentException('Demo 2 identity requires a NetBird gateway');
    $q = $db->prepare('SELECT 1 FROM partnerRouter WHERE ip=INET_ATON(?) LIMIT 1');
    $q->bind_param('s', $gateway); $q->execute();
    $registered = (bool)$q->get_result()->fetch_row(); $q->close();
    if (!$registered) throw new InvalidArgumentException('Registered gateway required');
    $body = http_build_query(['action'=>'resolve', 'identity_proof'=>$proof]);
    $url = 'http://' . $gateway . '/script/appDemoSshIdentity.php';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body,
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>5,
            CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($raw === false || $code !== 200) throw new RuntimeException('Gateway identity could not be verified');
    } else {
        $context = stream_context_create(['http'=>['method'=>'POST', 'content'=>$body,
            'header'=>"Content-Type: application/x-www-form-urlencoded\r\n", 'timeout'=>5, 'follow_location'=>0]]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) throw new RuntimeException('Gateway identity could not be verified');
    }
    $identity = json_decode($raw, true);
    if (!is_array($identity) || ($identity['ok'] ?? false) !== true ||
        !is_int($identity['unit_id'] ?? null) || $identity['unit_id'] < 1 ||
        !is_bool($identity['eligible'] ?? null))
        throw new RuntimeException('Invalid gateway identity response');
    return $identity;
}

function demoSshReconcilePending(mysqli $db, array $session): array
{
    if (empty($session['gatewayVerified']) || !in_array($session['state'],
        ['awaiting_node_a','demo_infected','awaiting_node_b'], true) || (int)$session['secondsRemaining'] <= 0)
        return $session;
    $id = (int)$session['demoSshSessionId'];
    $db->begin_transaction();
    $q = $db->prepare('SELECT state FROM demoSshSession WHERE demoSshSessionId=? FOR UPDATE');
    $q->bind_param('i', $id); $q->execute(); $current = $q->get_result()->fetch_assoc(); $q->close();
    if (!$current || !in_array($current['state'], ['awaiting_node_a','demo_infected','awaiting_node_b'], true)) {
        $db->commit();
        return $session;
    }
    // Sensor-authenticated callbacks can beat gateway confession. Attach only
    // the exact translated tuple whose owner-gateway evidence proves this unit.
    $q = $db->prepare("UPDATE demoSshAttempt a JOIN demoSshSession s ON s.demoSshSessionId=? JOIN demoSshNodeB n ON n.demoSshNodeBId=s.demoSshNodeBId
        SET a.demoSshSessionId=s.demoSshSessionId,a.unitId=s.unitId,a.correlation='unit'
        WHERE a.demoSshSessionId IS NULL AND a.demoSshNodeBId=s.demoSshNodeBId
        AND a.credentialGeneration=s.credentialGeneration AND a.sourceIp=s.sourceIp
        AND a.destinationPort=n.port AND a.created>=s.created AND a.created<s.expires
        AND (a.unitId IS NULL OR a.unitId=s.unitId)
        AND s.unitId=(SELECT hr.remoteUnitId FROM hackReport hr WHERE hr.ip=a.sourceIp
            AND hr.port=a.sourcePort AND hr.sentByIp=n.ip AND hr.ownerConfirmedTime IS NOT NULL
            AND hr.remoteUnitId IS NOT NULL AND COALESCE(hr.lastSeen,hr.created)>=a.created
            ORDER BY hr.reportId DESC LIMIT 1)");
    $q->bind_param('i', $id); $q->execute(); $q->close();
    $q = $db->prepare('SELECT sourcePort,CAST(credentialValid AS UNSIGNED) accepted FROM demoSshAttempt WHERE demoSshSessionId=? ORDER BY demoSshAttemptId DESC');
    $q->bind_param('i', $id); $q->execute(); $attempts = $q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
    if ($attempts) {
        $count = count($attempts); $port = (int)$attempts[0]['sourcePort'];
        $state = $count !== 1 || !(int)$attempts[0]['accepted'] ? 'owner_clear_required' : $current['state'];
        $q = $db->prepare("UPDATE demoSshSession SET attempts=?,nodeBSourcePort=?,state=?,completed=IF(?='owner_clear_required',NOW(),completed),lastSeen=NOW() WHERE demoSshSessionId=?");
        $q->bind_param('iissi', $count, $port, $state, $state, $id); $q->execute(); $q->close();
        $session['attempts'] = $count;
        $session['nodeBSourcePort'] = $port;
        $session['nodeBLoginAccepted'] = (int)$attempts[0]['accepted'];
        $session['state'] = $state;
    }
    $db->commit();
    return $session;
}
