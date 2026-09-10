<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';
include '../taraLib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function demoReply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function demoPost(): array
{
    $type = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    if (str_starts_with($type, 'application/json')) {
        $body = json_decode((string)file_get_contents('php://input'), true);
        return is_array($body) ? $body : [];
    }
    return $_POST;
}

function demoBearer(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    return preg_match('/^Bearer\s+(.+)$/i', $header, $m) ? trim($m[1]) : '';
}

function demoPublicSession(array $row): array
{
    return [
        'session_id' => (int)$row['demoSshSessionId'],
        'state' => (string)$row['state'],
        'node_a' => (string)$row['node_a'],
        'node_a_port' => (int)$row['nodeAPort'],
        'node_b' => (string)$row['node_b'],
        'node_b_port' => (int)$row['nodeBPort'],
        'username' => (string)$row['username'],
        'attempts' => (int)$row['attempts'],
        'expires' => (string)$row['expires'],
        // Let clients show a timezone-independent live countdown. The DB
        // remains authoritative and status polling corrects any clock drift.
        'seconds_remaining' => max(0, strtotime((string)$row['expires']) - time()),
        'completed' => $row['completed'] === null ? null : (string)$row['completed'],
        'node_a_observed' => !empty($row['nodeAEvidenceId']),
        'unit_marked' => !empty($row['demoInfectionObserved']) || in_array((string)$row['state'], ['demo_infected','awaiting_node_b','cleared','owner_clear_required'], true),
        'node_b_observed' => !empty($row['nodeBEvidenceId']),
        'progress_message' => (string)($row['progressMessage'] ?? '')
    ];
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$input = $method === 'POST' ? demoPost() : $_GET;
$action = strtolower(trim((string)($input['action'] ?? ($method === 'GET' ? 'status' : 'create'))));
$sender = getSenderIp();
if (filter_var($sender, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
    demoReply(400, ['ok' => false, 'error' => 'Unable to identify calling IPv4 client']);
}

try {
    $conn = getConnection();

    if ($action === 'create') {
        if ($method !== 'POST') demoReply(405, ['ok' => false, 'error' => 'POST required']);
        $setupId = filter_var($input['setup_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($setupId === false) demoReply(400, ['ok' => false, 'error' => 'Valid setup_id required']);

        $conn->begin_transaction();
        $stmt = $conn->prepare("SELECT d.demoSshSetupId,d.demoSshNodeBId,INET_NTOA(d.nodeAIp) node_a,d.nodeAPort,d.challengeTtlSeconds FROM demoSshSetup d JOIN demoSshNodeB n ON n.demoSshNodeBId=d.demoSshNodeBId WHERE d.demoSshSetupId=? AND d.active=b'1' AND n.active=b'1' FOR UPDATE");
        $stmt->bind_param('i', $setupId);
        $stmt->execute();
        $setup = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$setup) { $conn->rollback(); demoReply(404, ['ok' => false, 'error' => 'Demo setup unavailable']); }

        $stmt = $conn->prepare("SELECT why FROM internalInfections WHERE ip=INET_ATON(?) AND active=b'1' AND severity>1 ORDER BY infectionId DESC LIMIT 1");
        $stmt->bind_param('s', $sender);
        $stmt->execute();
        $infection = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($infection && !str_starts_with((string)$infection['why'], 'DEMO:')) {
            $conn->rollback();
            demoReply(409, ['ok' => false, 'error' => 'Device has non-demo infection evidence; owner clearance is required']);
        }

        // Lock Node B while deciding whether to reuse or rotate its credential.
        // Every overlapping classroom session shares the same generation.
        $stmt = $conn->prepare("SELECT demoSshNodeBId,name,INET_NTOA(ip) node_b,port nodeBPort,username,passwordPlain,passwordHash,credentialGeneration FROM demoSshNodeB WHERE demoSshNodeBId=? AND active=b'1' FOR UPDATE");
        $stmt->bind_param('i', $setup['demoSshNodeBId']);
        $stmt->execute();
        $node = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$node) { $conn->rollback(); demoReply(404, ['ok' => false, 'error' => 'Node B unavailable']); }
        // Node B intentionally uses one stable classroom credential for every
        // concurrent tester. Do not rotate or personalize it: legitimate users
        // may share a service credential, and TaraSec must distinguish their
        // traffic by the complete IP/port tuple resolved through conntrack to
        // the true unit. The honeypot is non-executing, so this credential does
        // not grant access to a real account or shell.
        $node['username'] = 'demo';
        $node['passwordPlain'] = '1';
        $node['passwordHash'] = hash('sha256', $node['passwordPlain']);
        $node['credentialGeneration'] = max(1, (int)$node['credentialGeneration']);
        $stmt = $conn->prepare("UPDATE demoSshNodeB SET username=?,passwordPlain=?,passwordHash=?,credentialGeneration=?,credentialCreated=COALESCE(credentialCreated,NOW()) WHERE demoSshNodeBId=? AND (username<>? OR passwordPlain<>? OR passwordHash<>? OR credentialGeneration<>?)");
        $stmt->bind_param('sssiisssi', $node['username'], $node['passwordPlain'], $node['passwordHash'], $node['credentialGeneration'], $node['demoSshNodeBId'], $node['username'], $node['passwordPlain'], $node['passwordHash'], $node['credentialGeneration']);
        $stmt->execute();
        $stmt->close();
        $accessToken = bin2hex(random_bytes(24));
        $accessHash = hash('sha256', $accessToken);
        // Classroom demonstrations need enough time for instruction and many\n        // concurrent students to complete both SSH steps.\n        $ttl = max(60, min(1800, (int)$setup['challengeTtlSeconds']));
        $unitId = null;
        $stmt = $conn->prepare("SELECT unitId FROM unit WHERE ipAddress=INET_ATON(?) ORDER BY lastSeen DESC,unitId DESC LIMIT 1");
        $stmt->bind_param('s', $sender);
        $stmt->execute();
        $unitRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($unitRow) $unitId = (int)$unitRow['unitId'];
        $stmt = $conn->prepare("INSERT INTO demoSshSession(demoSshSetupId,demoSshNodeBId,sourceIp,unitId,credentialGeneration,accessTokenHash,expires) VALUES(?,?,INET_ATON(?),?,?,?,DATE_ADD(NOW(),INTERVAL ? SECOND))");
        $stmt->bind_param('iisiisi', $setupId, $node['demoSshNodeBId'], $sender, $unitId, $node['credentialGeneration'], $accessHash, $ttl);
        $stmt->execute();
        $sessionId = (int)$conn->insert_id;
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO demoSshEvent(demoSshSessionId,eventType,sourceIp,details) VALUES(?,'created',INET_ATON(?),'shared Node B credential assigned')");
        $stmt->bind_param('is', $sessionId, $sender);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        demoReply(201, ['ok' => true, 'session_id' => $sessionId, 'session_token' => $accessToken, 'state' => 'awaiting_node_a', 'node_a' => $setup['node_a'], 'node_a_port' => (int)$setup['nodeAPort'], 'node_b' => $node['node_b'], 'node_b_port' => (int)$node['nodeBPort'], 'username' => $node['username'], 'password' => $node['passwordPlain'], 'credential_generation' => (int)$node['credentialGeneration'], 'expires_in' => $ttl]);
    }

    if ($action === 'validate') {
        if ($method !== 'POST') demoReply(405, ['ok' => false, 'error' => 'POST required']);
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $sourceIp = trim((string)($input['source_ip'] ?? ''));
        $sourcePort = filter_var($input['source_port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $destinationPort = filter_var($input['destination_port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if (!preg_match('/^[A-Za-z0-9_-]{1,16}$/', $username) || filter_var($sourceIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $sourcePort === false || $destinationPort === false) demoReply(400, ['ok' => false, 'error' => 'Invalid challenge report']);

        $conn->begin_transaction();
        $stmt = $conn->prepare("SELECT demoSshNodeBId,INET_NTOA(ip) node_b,port nodeBPort,sensorTokenHash,username,passwordHash,credentialGeneration FROM demoSshNodeB WHERE ip=INET_ATON(?) AND port=? AND active=b'1' FOR UPDATE");
        $stmt->bind_param('si', $sender, $destinationPort);
        $stmt->execute();
        $node = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$node || !hash_equals((string)$node['sensorTokenHash'], hash('sha256', demoBearer()))) { $conn->rollback(); demoReply(403, ['ok' => false, 'error' => 'Sensor authentication failed']); }
        $passwordOk = hash_equals((string)$node['username'], $username) && hash_equals((string)$node['passwordHash'], hash('sha256', $password));

        // The normalized Node B observation may already carry TaraSec's unit
        // attribution. Match the complete tuple; never equate a shared IP with
        // a unit when concurrent sessions make that ambiguous.
        // ssh_session_connect is emitted before authentication calls this
        // endpoint, but rsyslog and conntrack attribution are asynchronous.
        // Give that exact tuple a short bounded window to acquire its unit id;
        // otherwise concurrent clients behind one NAT address would be
        // needlessly reduced to the ambiguous source-IP fallback below.
        $nodeBEvidence = null;
        for ($wait = 0; $wait < 8; $wait++) {
            $stmt = $conn->prepare("SELECT syslogThreatId,COALESCE(confirmed_unit_id,unit_id) resolvedUnitId FROM syslogThreat WHERE src_ip=INET_ATON(?) AND src_port=? AND dst_ip=INET_ATON(?) AND dst_port=? AND created>=NOW()-INTERVAL 2 MINUTE ORDER BY syslogThreatId DESC LIMIT 1");
            $stmt->bind_param('sisi', $sourceIp, $sourcePort, $node['node_b'], $destinationPort);
            $stmt->execute();
            $candidate = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($candidate) $nodeBEvidence = $candidate;
            if ($candidate && $candidate['resolvedUnitId'] !== null) break;
            if ($wait < 7) usleep(250000);
        }

        $resolvedUnitId = $nodeBEvidence && $nodeBEvidence['resolvedUnitId'] !== null ? (int)$nodeBEvidence['resolvedUnitId'] : null;
        $sql = "SELECT s.*,INET_NTOA(d.nodeAIp) node_a,d.nodeAPort FROM demoSshSession s JOIN demoSshSetup d ON d.demoSshSetupId=s.demoSshSetupId WHERE s.demoSshNodeBId=? AND s.credentialGeneration=? AND s.state IN ('awaiting_node_a','demo_infected','awaiting_node_b') AND s.expires>NOW()";
        if ($resolvedUnitId !== null) {
            $sql .= " AND s.unitId=? ORDER BY s.created DESC LIMIT 2 FOR UPDATE";
            $stmt = $conn->prepare($sql); $stmt->bind_param('iii', $node['demoSshNodeBId'], $node['credentialGeneration'], $resolvedUnitId);
            $correlation = 'unit';
        } else {
            $sql .= " AND s.sourceIp=INET_ATON(?) ORDER BY s.created DESC LIMIT 2 FOR UPDATE";
            $stmt = $conn->prepare($sql); $stmt->bind_param('iis', $node['demoSshNodeBId'], $node['credentialGeneration'], $sourceIp);
            $correlation = 'single_source';
        }
        $stmt->execute();
        $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $row = count($matches) === 1 ? $matches[0] : null;
        if (!$row) $correlation = count($matches) > 1 ? 'pending' : 'none';
        $sessionId = $row ? (int)$row['demoSshSessionId'] : null;
        $stmt = $conn->prepare("INSERT INTO demoSshAttempt(demoSshNodeBId,demoSshSessionId,sourceIp,sourcePort,destinationPort,unitId,credentialGeneration,credentialValid,correlation) VALUES(?,?,INET_ATON(?),?,?,?,?,?,?)");
        $validBit = $passwordOk ? 1 : 0;
        $stmt->bind_param('iisiiiiis', $node['demoSshNodeBId'], $sessionId, $sourceIp, $sourcePort, $destinationPort, $resolvedUnitId, $node['credentialGeneration'], $validBit, $correlation);
        $stmt->execute();
        $stmt->close();

        $state = $row ? (string)$row['state'] : 'awaiting_attribution';
        if ($row) {
            $attempts = (int)$row['attempts'] + 1;
            $stmt = $conn->prepare("SELECT syslogThreatId FROM syslogThreat WHERE src_ip=? AND dst_ip=INET_ATON(?) AND dst_port=? AND is_attack<>0 AND created>=? ORDER BY syslogThreatId DESC LIMIT 1");
            $stmt->bind_param('isis', $row['sourceIp'], $row['node_a'], $row['nodeAPort'], $row['created']);
            $stmt->execute(); $nodeA = $stmt->get_result()->fetch_assoc(); $stmt->close();
            // Locate the active infection created during this session. Never
            // clear an older record, even when it belongs to the same unit or
            // address: independent evidence remains owner-controlled.
            if ($row['unitId'] !== null) {
                $stmt = $conn->prepare("SELECT infectionId FROM internalInfections WHERE unitId=? AND active=b'1' AND COALESCE(lastSeen,inserted)>=? ORDER BY infectionId DESC LIMIT 1");
                $stmt->bind_param('is', $row['unitId'], $row['created']);
            } else {
                $stmt = $conn->prepare("SELECT infectionId FROM internalInfections WHERE ip=? AND active=b'1' AND COALESCE(lastSeen,inserted)>=? ORDER BY infectionId DESC LIMIT 1");
                $stmt->bind_param('is', $row['sourceIp'], $row['created']);
            }
            $stmt->execute(); $demoInfection = $stmt->get_result()->fetch_assoc(); $stmt->close();
            $qualifies = $passwordOk && $attempts === 1 && $nodeA && $demoInfection;
            $state = $qualifies ? 'cleared' : 'owner_clear_required';
            $nodeAId = $nodeA ? (int)$nodeA['syslogThreatId'] : null;
            $nodeBId = $nodeBEvidence ? (int)$nodeBEvidence['syslogThreatId'] : null;
            $stmt = $conn->prepare("UPDATE demoSshSession SET attempts=?,state=?,nodeBSourcePort=?,nodeAEvidenceId=?,nodeBEvidenceId=?,completed=NOW(),lastSeen=NOW() WHERE demoSshSessionId=?");
            $stmt->bind_param('isiiii', $attempts, $state, $sourcePort, $nodeAId, $nodeBId, $sessionId); $stmt->execute(); $stmt->close();
            $event = $qualifies ? 'cleared' : 'rejected';
            $details = $qualifies ? 'validated first-attempt sequence' : (!$passwordOk ? 'credential mismatch' : (!$nodeA ? 'Node A evidence missing' : (!$demoInfection ? 'session infection missing' : 'not first attempt')));
            $stmt = $conn->prepare("INSERT INTO demoSshEvent(demoSshSessionId,eventType,nodeIp,sourceIp,syslogThreatId,details) VALUES(?,?,INET_ATON(?),INET_ATON(?),?,?)");
            $stmt->bind_param('isssis', $sessionId, $event, $node['node_b'], $sourceIp, $nodeBId, $details); $stmt->execute(); $stmt->close();
            if ($nodeBId) { $stmt = $conn->prepare("UPDATE syslogThreat SET demoSshSessionId=? WHERE syslogThreatId=?"); $stmt->bind_param('ii', $sessionId, $nodeBId); $stmt->execute(); $stmt->close(); }
            if ($qualifies) {
                $why = 'DEMO:SSH session ' . $sessionId . ': validated and cleared';
                $stmt = $conn->prepare("UPDATE internalInfections SET active=b'0',handled=b'0',why=?,lastSeen=NOW() WHERE infectionId=?");
                $stmt->bind_param('si', $why, $demoInfection['infectionId']); $stmt->execute(); $stmt->close();
            }
        }
        $conn->commit();
        demoReply(200, ['ok' => true, 'accepted' => $passwordOk, 'state' => $state, 'correlation' => $correlation]);
    }

    if ($action === 'status') {
        $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionToken = (string)($input['session_token'] ?? '');
        if ($sessionId === false) demoReply(400, ['ok' => false, 'error' => 'Valid session_id required']);
        if (strlen($sessionToken) < 32) demoReply(403, ['ok' => false, 'error' => 'Session token required']);
        $accessHash = hash('sha256', $sessionToken);
        $stmt = $conn->prepare("SELECT s.*,INET_NTOA(d.nodeAIp) node_a,d.nodeAPort,INET_NTOA(n.ip) node_b,n.port nodeBPort,n.username FROM demoSshSession s JOIN demoSshSetup d ON d.demoSshSetupId=s.demoSshSetupId JOIN demoSshNodeB n ON n.demoSshNodeBId=s.demoSshNodeBId WHERE s.demoSshSessionId=? AND s.accessTokenHash=? LIMIT 1");
        $stmt->bind_param('is', $sessionId, $accessHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) demoReply(404, ['ok' => false, 'error' => 'Demo session not found']);
        // Reconcile asynchronous Node A/gateway reports into the session before
        // replying. The Android client polls this endpoint; without this step
        // the row remains awaiting_node_a even after the DB has the evidence.
        if (in_array($row['state'], ['awaiting_node_a','demo_infected','awaiting_node_b'], true)) {
            $stmt = $conn->prepare("SELECT t.syslogThreatId FROM syslogThreat t JOIN demoSshSession s ON s.demoSshSessionId=? WHERE t.dst_ip=INET_ATON(?) AND t.dst_port=? AND t.is_attack<>0 AND t.created>=s.created AND (t.src_ip=s.sourceIp OR (s.unitId IS NOT NULL AND COALESCE(t.confirmed_unit_id,t.unit_id)=s.unitId)) ORDER BY t.syslogThreatId DESC LIMIT 1");
            $stmt->bind_param('isi', $sessionId, $row['node_a'], $row['nodeAPort']);
            $stmt->execute();
            $nodeAEvidence = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $stmt = $conn->prepare("SELECT i.infectionId FROM internalInfections i JOIN demoSshSession s ON s.demoSshSessionId=? WHERE i.active=b'1' AND COALESCE(i.lastSeen,i.inserted)>=s.created AND (i.ip=s.sourceIp OR (s.unitId IS NOT NULL AND i.unitId=s.unitId)) ORDER BY i.infectionId DESC LIMIT 1");
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $demoInfection = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $nextState = $row['state'];
            if ($nodeAEvidence && $demoInfection) {
                $nextState = 'awaiting_node_b';
            } elseif ($nodeAEvidence || $demoInfection) {
                $nextState = 'demo_infected';
            }
            $nodeAEvidenceId = $nodeAEvidence ? (int)$nodeAEvidence['syslogThreatId'] : null;
            if ($nextState !== $row['state'] || ($nodeAEvidenceId && empty($row['nodeAEvidenceId']))) {
                $previousState = (string)$row['state'];
                $stmt = $conn->prepare("UPDATE demoSshSession SET state=?,nodeAEvidenceId=COALESCE(nodeAEvidenceId,?),lastSeen=NOW() WHERE demoSshSessionId=?");
                $stmt->bind_param('sii', $nextState, $nodeAEvidenceId, $sessionId);
                $stmt->execute();
                $stmt->close();
                if ($nextState !== $previousState) {
                    $details = $nextState === 'awaiting_node_b'
                        ? 'Node A rejection and unit infection observed'
                        : ($nodeAEvidence ? 'Node A rejection observed; awaiting infection report' : 'Unit infection observed; awaiting Node A report');
                    $stmt = $conn->prepare("INSERT INTO demoSshEvent(demoSshSessionId,eventType,sourceIp,syslogThreatId,details) VALUES(?,'node_a_observed',INET_ATON(?),?,?)");
                    $stmt->bind_param('isis', $sessionId, $sender, $nodeAEvidenceId, $details);
                    $stmt->execute();
                    $stmt->close();
                }
                $row['state'] = $nextState;
                if ($nodeAEvidenceId) $row['nodeAEvidenceId'] = $nodeAEvidenceId;
            }
            $row['demoInfectionObserved'] = $demoInfection ? 1 : 0;
            $row['progressMessage'] = $nextState === 'awaiting_node_b'
                ? 'Node A and gateway reports received; continue with Node B'
                : ($nextState === 'demo_infected'
                    ? ($nodeAEvidence ? 'Node A report received; waiting for gateway infection update' : 'Gateway infection update received; waiting for Node A report')
                    : 'Waiting for Node A rejection report');
        }
        if (strtotime((string)$row['expires']) <= time() && in_array($row['state'], ['awaiting_node_a','demo_infected','awaiting_node_b'], true)) {
            $stmt = $conn->prepare("UPDATE demoSshSession SET state='expired',completed=NOW(),lastSeen=NOW() WHERE demoSshSessionId=?");
            $stmt->bind_param('i', $sessionId); $stmt->execute(); $stmt->close(); $row['state'] = 'expired'; $row['completed'] = gmdate('Y-m-d H:i:s');
        }
        demoReply(200, ['ok' => true, 'session' => demoPublicSession($row)]);
    }

    demoReply(400, ['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('appDemoSshSession.php failed: ' . $e->getMessage());
    demoReply(500, ['ok' => false, 'error' => 'Demo service unavailable']);
}
