<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function contextReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function contextPeerIp(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (strncasecmp($ip, '::ffff:', 7) === 0) $ip = substr($ip, 7);
    return $ip;
}

try {
    $ip = contextPeerIp();
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        contextReply(403, ['ok'=>false,'error'=>'invalid_gateway_ip']);
    }

    $conn = getConnection();
    $stmt = $conn->prepare('SELECT 1 FROM partnerRouter WHERE ip=INET_ATON(?) LIMIT 1');
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $registered = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$registered) {
        $conn->close();
        contextReply(403, ['ok'=>false,'error'=>'unregistered_gateway']);
    }

    $sharedTargets = [];
    $sql = "SELECT INET_NTOA(dst_ip) dst_ip,dst_port,COALESCE(protocol,'') protocol," .
           "COALESCE(service,'') service,COUNT(DISTINCT owner_id) owners," .
           "COUNT(DISTINCT CONCAT(owner_id,':',COALESCE(confirmed_unit_id,unit_id))) units," .
           "SUM(`count`) occurrences,MAX(COALESCE(severity,0)) max_severity " .
           "FROM syslogThreat WHERE COALESCE(lastSeen,created)>=NOW()-INTERVAL 7 DAY " .
           "AND owner_id IS NOT NULL AND COALESCE(confirmed_unit_id,unit_id) IS NOT NULL " .
           "GROUP BY dst_ip,dst_port,protocol,service HAVING COUNT(DISTINCT owner_id)>=2 " .
           "ORDER BY owners DESC,units DESC,occurrences DESC LIMIT 40";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $sharedTargets[] = [
            'dst_ip'=>$row['dst_ip'],
            'dst_port'=>(int)$row['dst_port'],
            'protocol'=>$row['protocol'],
            'service'=>$row['service'],
            'owners'=>(int)$row['owners'],
            'units'=>(int)$row['units'],
            'occurrences'=>(int)$row['occurrences'],
            'max_severity'=>(int)$row['max_severity'],
        ];
    }
    $result->free();

    $knownNodes = [];
    $sql = "SELECT INET_NTOA(st.src_ip) node_ip,COUNT(*) records,SUM(st.`count`) occurrences," .
           "COUNT(DISTINCT st.dst_ip) targets,MAX(COALESCE(st.severity,0)) max_severity " .
           "FROM syslogThreat st JOIN partnerRouter pr ON pr.ip=st.src_ip " .
           "WHERE COALESCE(st.lastSeen,st.created)>=NOW()-INTERVAL 7 DAY " .
           "GROUP BY st.src_ip ORDER BY max_severity DESC,occurrences DESC LIMIT 40";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $knownNodes[] = [
            'node_ip'=>$row['node_ip'],
            'records'=>(int)$row['records'],
            'occurrences'=>(int)$row['occurrences'],
            'targets'=>(int)$row['targets'],
            'max_severity'=>(int)$row['max_severity'],
        ];
    }
    $result->free();

    $conn->close();
    contextReply(200, [
        'ok'=>true,
        'generated'=>gmdate('c'),
        'window_days'=>7,
        'shared_targets'=>$sharedTargets,
        'known_network_nodes'=>$knownNodes,
        'note'=>'Aggregated TaraSec context only; it is supporting evidence, not infection state.'
    ]);
} catch (Throwable $e) {
    error_log('gatewayAiContext.php: '.$e->getMessage());
    contextReply(503, ['ok'=>false,'error'=>'global_ai_context_unavailable']);
}
