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

function demoNodeToken(): string
{
    // Some Apache/PHP configurations intentionally omit Authorization from
    // $_SERVER. Prefer a purpose-specific sensor header and retain Bearer as
    // compatibility fallback.
    $nodeHeader = trim((string)($_SERVER['HTTP_X_TARASEC_NODE_TOKEN'] ?? ''));
    if ($nodeHeader !== '') return $nodeHeader;
    $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    return preg_match('/^Bearer\s+(.+)$/i', $authorization, $m) ? trim($m[1]) : '';
}

function demoPublicSession(array $row): array
{
    return [
        'session_id' => (int)$row['demoSshSessionId'],
        'state' => (string)$row['state'],
        'source_ip' => isset($row['sourceIpText']) ? (string)$row['sourceIpText'] : '',
        'node_a' => (string)$row['node_a'],
        'node_a_port' => (int)$row['nodeAPort'],
        'node_b' => (string)$row['node_b'],
        'node_b_port' => (int)$row['nodeBPort'],
        'username' => (string)$row['username'],
        'attempts' => (int)$row['attempts'],
        'expires' => (string)$row['expires'],
        // Let clients show a timezone-independent live countdown. The DB
        // remains authoritative and status polling corrects any clock drift.
        'seconds_remaining' => max(0, (int)($row['secondsRemaining'] ?? 0)),
        'completed' => $row['completed'] === null ? null : (string)$row['completed'],
        'node_a_observed' => !empty($row['nodeAEvidenceId']),
        'unit_marked' => !empty($row['demoInfectionObserved']) || in_array((string)$row['state'], ['demo_infected','awaiting_node_b','cleared','owner_clear_required'], true),
        'node_b_observed' => !empty($row['nodeBEvidenceId']),
        'node_b_login_accepted' => !array_key_exists('nodeBLoginAccepted', $row) || $row['nodeBLoginAccepted'] === null
            ? null
            : ((int)$row['nodeBLoginAccepted'] === 1),
        'progress_message' => (string)($row['progressMessage'] ?? ''),
        'operational_warning' => (string)($row['operationalWarning'] ?? ''),
        'operationally_ready' => empty($row['operationalWarning'])
    ];
}

function demoOperationalWarning(mysqli $conn, array $ips): string
{
    $stale = [];
    $stmt = $conn->prepare("SELECT partnerStatusReceived,TIMESTAMPDIFF(SECOND,partnerStatusReceived,NOW()) age FROM partnerRouter WHERE ip=INET_ATON(?) LIMIT 1");
    foreach (array_unique(array_filter($ips)) as $label => $ip) {
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $health = $stmt->get_result()->fetch_assoc();
        if (!$health || $health['partnerStatusReceived'] === null || (int)$health['age'] > 300) {
            $stale[] = is_string($label) ? $label : $ip;
        }
    }
    $stmt->close();
    return $stale
        ? 'Operational issue: no recent status report from ' . implode(', ', $stale) . '; demo evidence may be unreliable.'
        : '';
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

    if ($action === 'eligibility') {
        $stmt = $conn->prepare("SELECT why FROM internalInfections WHERE ip=INET_ATON(?) AND active=b'1' AND severity>1 ORDER BY infectionId DESC LIMIT 1");
        $stmt->bind_param('s', $sender);
        $stmt->execute();
        $infection = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $eligible = !$infection;
        $demoResetAvailable = $infection && str_starts_with((string)$infection['why'], 'DEMO:');
        $health = $conn->query("SELECT INET_NTOA(d.nodeAIp) node_a,INET_NTOA(n.ip) node_b FROM demoSshSetup d JOIN demoSshNodeB n ON n.demoSshNodeBId=d.demoSshNodeBId WHERE d.active=b'1' AND n.active=b'1' ORDER BY d.demoSshSetupId LIMIT 1")->fetch_assoc();
        $operationalWarning = demoOperationalWarning($conn, [
            'gateway' => $sender,
            'Node A' => $health['node_a'] ?? '',
            'Node B' => $health['node_b'] ?? ''
        ]);
        demoReply(200, [
            'ok' => true,
            'eligible' => $eligible,
            'next' => $eligible ? 'demo' : 'remediation',
            'demo_reset_available' => (bool)$demoResetAvailable,
            'operational_warning' => $operationalWarning,
            'operationally_ready' => $operationalWarning === '',
            // Do not disclose infection state to an unauthenticated demo caller.
            'message' => $eligible
                ? 'This unit may start the demonstration'
                : 'This unit needs a security review before the demonstration can start'
        ]);
    }

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
        if ($infection) {
            $conn->rollback();
            demoReply(409, [
                'ok' => false,
                'error' => 'Security review required before this demonstration can start',
                'remediation_required' => true,
                'demo_reset_available' => str_starts_with((string)$infection['why'], 'DEMO:')
            ]);
        }

        // Starting over is only valid after the authenticated client has
        // explicitly cancelled its previous session. This prevents abandoned
        // active rows from making a later Node B callback ambiguous.
        $stmt = $conn->prepare("SELECT demoSshSessionId FROM demoSshSession WHERE demoSshSetupId=? AND sourceIp=INET_ATON(?) AND state IN ('awaiting_node_a','demo_infected','awaiting_node_b') AND expires>NOW() ORDER BY created DESC LIMIT 1 FOR UPDATE");
        $stmt->bind_param('is', $setupId, $sender);
        $stmt->execute();
        $activeSession = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($activeSession) {
            $conn->rollback();
            demoReply(409, [
                'ok' => false,
                'error' => 'Close the active Demo 2 session before starting another',
                'active_session_id' => (int)$activeSession['demoSshSessionId']
            ]);
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
        // Demo 2 has a fixed classroom window. Keep the interval literal:
        // this MariaDB deployment evaluated bound interval values as zero.
        $stmt = $conn->prepare("INSERT INTO demoSshSession(demoSshSetupId,demoSshNodeBId,sourceIp,unitId,credentialGeneration,accessTokenHash,expires) VALUES(?,?,INET_ATON(?),?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))");
        $stmt->bind_param('iisiis', $setupId, $node['demoSshNodeBId'], $sender, $unitId, $node['credentialGeneration'], $accessHash);
        $stmt->execute();
        $sessionId = (int)$conn->insert_id;
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO demoSshEvent(demoSshSessionId,eventType,sourceIp,details) VALUES(?,'created',INET_ATON(?),'shared Node B credential assigned')");
        $stmt->bind_param('is', $sessionId, $sender);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        $operationalWarning = demoOperationalWarning($conn, ['gateway' => $sender, 'Node A' => $setup['node_a'], 'Node B' => $node['node_b']]);
        demoReply(201, ['ok' => true, 'session_id' => $sessionId, 'session_token' => $accessToken, 'state' => 'awaiting_node_a', 'source_ip' => $sender, 'node_a' => $setup['node_a'], 'node_a_port' => (int)$setup['nodeAPort'], 'node_b' => $node['node_b'], 'node_b_port' => (int)$node['nodeBPort'], 'username' => $node['username'], 'password' => $node['passwordPlain'], 'credential_generation' => (int)$node['credentialGeneration'], 'expires_in' => $ttl, 'operational_warning' => $operationalWarning, 'operationally_ready' => $operationalWarning === '']);
    }

    if ($action === 'cancel') {
        if ($method !== 'POST') demoReply(405, ['ok' => false, 'error' => 'POST required']);
        $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionToken = (string)($input['session_token'] ?? '');
        if ($sessionId === false) demoReply(400, ['ok' => false, 'error' => 'Valid session_id required']);
        if (strlen($sessionToken) < 32) demoReply(403, ['ok' => false, 'error' => 'Session token required']);
        $accessHash = hash('sha256', $sessionToken);

        $conn->begin_transaction();
        $stmt = $conn->prepare("SELECT state FROM demoSshSession WHERE demoSshSessionId=? AND accessTokenHash=? AND sourceIp=INET_ATON(?) LIMIT 1 FOR UPDATE");
        $stmt->bind_param('iss', $sessionId, $accessHash, $sender);
        $stmt->execute();
        $sessionRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$sessionRow) { $conn->rollback(); demoReply(404, ['ok' => false, 'error' => 'Demo session not found']); }

        $wasActive = in_array((string)$sessionRow['state'], ['awaiting_node_a','demo_infected','awaiting_node_b'], true);
        if ($wasActive) {
            $stmt = $conn->prepare("UPDATE demoSshSession SET state='cancelled',completed=NOW(),lastSeen=NOW() WHERE demoSshSessionId=?");
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO demoSshEvent(demoSshSessionId,eventType,sourceIp,details) VALUES(?,'cancelled',INET_ATON(?),'Session closed by authenticated client')");
            $stmt->bind_param('is', $sessionId, $sender);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
        demoReply(200, ['ok' => true, 'state' => $wasActive ? 'cancelled' : (string)$sessionRow['state']]);
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
        if (!$node || !hash_equals((string)$node['sensorTokenHash'], hash('sha256', demoNodeToken()))) { $conn->rollback(); demoReply(403, ['ok' => false, 'error' => 'Sensor authentication failed']); }
        $passwordOk = hash_equals((string)$node['username'], $username) && hash_equals((string)$node['passwordHash'], hash('sha256', $password));

        // The normalized Node B observation may carry unit attribution in
        // syslogThreat. The normal report/confession protocol instead records
        // the authoritative owner unit in hackReport.remoteUnitId, so use that
        // as the fallback for the same source IP and translated port.
        // Match the complete tuple; never equate a shared IP with
        // a unit when concurrent sessions make that ambiguous.
        // ssh_session_connect is emitted before authentication calls this
        // endpoint, but rsyslog and conntrack attribution are asynchronous.
        // Give that exact tuple a short bounded window to acquire its unit id;
        // otherwise concurrent clients behind one NAT address would be
        // needlessly reduced to the ambiguous source-IP fallback below.
        $nodeBEvidence = null;
        for ($wait = 0; $wait < 8; $wait++) {
            $stmt = $conn->prepare("SELECT t.syslogThreatId,COALESCE(t.confirmed_unit_id,t.unit_id,(SELECT hr.remoteUnitId FROM hackReport hr WHERE hr.ip=t.src_ip AND hr.port=t.src_port AND hr.ownerConfirmedTime IS NOT NULL AND hr.remoteUnitId IS NOT NULL AND COALESCE(hr.lastSeen,hr.created)>=NOW()-INTERVAL 2 MINUTE ORDER BY hr.reportId DESC LIMIT 1)) resolvedUnitId FROM syslogThreat t WHERE t.src_ip=INET_ATON(?) AND t.src_port=? AND t.dst_ip=INET_ATON(?) AND t.dst_port=? AND t.created>=NOW()-INTERVAL 2 MINUTE ORDER BY t.syslogThreatId DESC LIMIT 1");
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
        if (!$row && count($matches) > 1) {
            // Closing the screen in an older client could leave an
            // awaiting_node_a row behind. Prefer the one uniquely progressed
            // session instead of making the later Node B callback ambiguous.
            $progressed = array_values(array_filter($matches, static function (array $match): bool {
                return !empty($match['nodeAEvidenceId'])
                    || in_array((string)$match['state'], ['demo_infected', 'awaiting_node_b'], true);
            }));
            if (count($progressed) >= 1) {
                // Results are newest-first. When gateway/NAT attribution is not
                // available, bind the callback to the newest session that has
                // already reached Node A. An abandoned older progressed session
                // must not leave every later Node B callback pending forever.
                $row = $progressed[0];
                if (count($progressed) > 1) $correlation = 'latest_progressed_source';
            }
        }
        if (!$row) $correlation = count($matches) > 1 ? 'pending' : 'none';
        $sessionId = $row ? (int)$row['demoSshSessionId'] : null;
        $stmt = $conn->prepare("INSERT INTO demoSshAttempt(demoSshNodeBId,demoSshSessionId,sourceIp,sourcePort,destinationPort,unitId,credentialGeneration,credentialValid,correlation) VALUES(?,?,INET_ATON(?),?,?,?,?,?,?)");
        $validBit = $passwordOk ? 1 : 0;
        $stmt->bind_param('iisiiiiis', $node['demoSshNodeBId'], $sessionId, $sourceIp, $sourcePort, $destinationPort, $resolvedUnitId, $node['credentialGeneration'], $validBit, $correlation);
        $stmt->execute();
        $stmt->close();

        // A successful, sensor-authenticated Node B login is authoritative
        // threat evidence, but the syslog observation alone never enters the
        // normal hackReport -> owner gateway -> confession path. Create one
        // deduplicated report for the exact translated tuple so taralink can
        // ask the gateway to resolve the real unit behind NAT.
        if ($row && $passwordOk) {
            $demoWhy = 'DEMO:SSH session ' . $sessionId . ': authenticated Node B login';
            $stmt = $conn->prepare("SELECT reportId FROM hackReport WHERE ip=INET_ATON(?) AND port=? AND sentByIp=INET_ATON(?) AND hrCategory='demo' AND why=? AND created>=? ORDER BY reportId DESC LIMIT 1");
            $stmt->bind_param('sisss', $sourceIp, $sourcePort, $sender, $demoWhy, $row['created']);
            $stmt->execute();
            $existingDemoReport = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$existingDemoReport) {
                $stmt = $conn->prepare("INSERT INTO hackReport(ip,port,sentByIp,status,hrCategory,why,severity,lastSeen) VALUES(INET_ATON(?),?,INET_ATON(?),'FirstTime','demo',?,7,NOW())");
                $stmt->bind_param('siss', $sourceIp, $sourcePort, $sender, $demoWhy);
                $stmt->execute();
                $stmt->close();
            }
        }

        $state = $row ? (string)$row['state'] : 'awaiting_attribution';
        if ($row) {
            $attempts = (int)$row['attempts'] + 1;
            $stmt = $conn->prepare("SELECT syslogThreatId,src_port FROM syslogThreat WHERE src_ip=? AND dst_ip=INET_ATON(?) AND dst_port=? AND is_attack<>0 AND created>=? ORDER BY syslogThreatId DESC LIMIT 1");
            $stmt->bind_param('isis', $row['sourceIp'], $row['node_a'], $row['nodeAPort'], $row['created']);
            $stmt->execute(); $nodeA = $stmt->get_result()->fetch_assoc(); $stmt->close();
            // internalInfections belongs to the source gateway, not the global
            // DB. The authoritative global proof that the gateway accepted the
            // Node A report is its owner-confirmed hackReport confession.
            $stmt = $conn->prepare("SELECT hr.reportId,hr.remoteUnitId FROM hackReport hr WHERE hr.ip=? AND hr.port IN (?,?) AND hr.sentByIp IN (INET_ATON(?),INET_ATON(?)) AND hr.ownerConfirmedTime IS NOT NULL AND COALESCE(hr.lastSeen,hr.created)>=? ORDER BY COALESCE(hr.lastSeen,hr.created) DESC,hr.reportId DESC LIMIT 1");
            $nodeASourcePort = $nodeA ? (int)$nodeA['src_port'] : 0;
            $stmt->bind_param('iiisss', $row['sourceIp'], $nodeASourcePort, $sourcePort, $row['node_a'], $node['node_b'], $row['created']);
            $stmt->execute(); $gatewayConfirmation = $stmt->get_result()->fetch_assoc(); $stmt->close();
            $qualifies = $passwordOk && $attempts === 1 && $nodeA && $gatewayConfirmation;
            $awaitingGateway = $passwordOk && $attempts === 1 && $nodeA && !$gatewayConfirmation;
            $state = $qualifies ? 'cleared' : ($awaitingGateway ? 'awaiting_node_b' : 'owner_clear_required');
            $nodeAId = $nodeA ? (int)$nodeA['syslogThreatId'] : null;
            $nodeBId = $nodeBEvidence ? (int)$nodeBEvidence['syslogThreatId'] : null;
            $stmt = $conn->prepare("UPDATE demoSshSession SET attempts=?,state=?,nodeBSourcePort=?,nodeAEvidenceId=?,nodeBEvidenceId=?,completed=IF(?='awaiting_node_b',NULL,NOW()),lastSeen=NOW() WHERE demoSshSessionId=?");
            $stmt->bind_param('isiiisi', $attempts, $state, $sourcePort, $nodeAId, $nodeBId, $state, $sessionId); $stmt->execute(); $stmt->close();
            $event = $qualifies ? 'cleared' : ($awaitingGateway ? 'node_b_attempt' : 'rejected');
            $details = $qualifies ? 'validated first-attempt sequence' : ($awaitingGateway ? 'login accepted; awaiting gateway confirmation' : (!$passwordOk ? 'credential mismatch' : (!$nodeA ? 'Node A evidence missing' : 'not first attempt')));
            $stmt = $conn->prepare("INSERT INTO demoSshEvent(demoSshSessionId,eventType,nodeIp,sourceIp,syslogThreatId,details) VALUES(?,?,INET_ATON(?),INET_ATON(?),?,?)");
            $stmt->bind_param('isssis', $sessionId, $event, $node['node_b'], $sourceIp, $nodeBId, $details); $stmt->execute(); $stmt->close();
            if ($nodeBId) { $stmt = $conn->prepare("UPDATE syslogThreat SET demoSshSessionId=? WHERE syslogThreatId=?"); $stmt->bind_param('ii', $sessionId, $nodeBId); $stmt->execute(); $stmt->close(); }
            if ($qualifies && $gatewayConfirmation) {
                $status = 'DEMO:SSH session ' . $sessionId . ': validated; gateway release required';
                $stmt = $conn->prepare("UPDATE hackReport SET status=?,lastSeen=NOW() WHERE reportId=?");
                $stmt->bind_param('si', $status, $gatewayConfirmation['reportId']); $stmt->execute(); $stmt->close();
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
        $stmt = $conn->prepare("SELECT s.*,INET_NTOA(s.sourceIp) sourceIpText,GREATEST(0,TIMESTAMPDIFF(SECOND,NOW(),s.expires)) secondsRemaining,INET_NTOA(d.nodeAIp) node_a,d.nodeAPort,INET_NTOA(n.ip) node_b,n.port nodeBPort,n.username,(SELECT CAST(a.credentialValid AS UNSIGNED) FROM demoSshAttempt a WHERE a.demoSshSessionId=s.demoSshSessionId ORDER BY a.demoSshAttemptId DESC LIMIT 1) nodeBLoginAccepted FROM demoSshSession s JOIN demoSshSetup d ON d.demoSshSetupId=s.demoSshSetupId JOIN demoSshNodeB n ON n.demoSshNodeBId=s.demoSshNodeBId WHERE s.demoSshSessionId=? AND s.accessTokenHash=? LIMIT 1");
        $stmt->bind_param('is', $sessionId, $accessHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) demoReply(404, ['ok' => false, 'error' => 'Demo session not found']);

        // The authenticated callback normally arrives a few milliseconds before
        // rsyslog has normalized ssh_login_success. Replace the earlier connect
        // or client-version evidence with the definitive success record once it
        // becomes available.
        if (isset($row['nodeBLoginAccepted']) && (int)$row['nodeBLoginAccepted'] === 1 && !empty($row['nodeBSourcePort'])) {
            $stmt = $conn->prepare("SELECT syslogThreatId FROM syslogThreat WHERE src_ip=? AND src_port=? AND dst_ip=INET_ATON(?) AND dst_port=? AND description LIKE 'ssh_login_success %' AND created>=? ORDER BY syslogThreatId DESC LIMIT 1");
            $stmt->bind_param('iisis', $row['sourceIp'], $row['nodeBSourcePort'], $row['node_b'], $row['nodeBPort'], $row['created']);
            $stmt->execute();
            $successEvidence = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($successEvidence && (int)$successEvidence['syslogThreatId'] !== (int)$row['nodeBEvidenceId']) {
                $successEvidenceId = (int)$successEvidence['syslogThreatId'];
                $stmt = $conn->prepare("UPDATE demoSshSession SET nodeBEvidenceId=?,lastSeen=NOW() WHERE demoSshSessionId=?");
                $stmt->bind_param('ii', $successEvidenceId, $sessionId);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare("UPDATE syslogThreat SET demoSshSessionId=? WHERE syslogThreatId=?");
                $stmt->bind_param('ii', $sessionId, $successEvidenceId);
                $stmt->execute();
                $stmt->close();
                $row['nodeBEvidenceId'] = $successEvidenceId;
            }
        }

        // Reconcile asynchronous Node A/gateway reports into the session before
        // replying. The Android client polls this endpoint; without this step
        // the row remains awaiting_node_a even after the DB has the evidence.
        $recoverPrematureFinalization = (string)$row['state'] === 'owner_clear_required'
            && isset($row['nodeBLoginAccepted'])
            && (int)$row['nodeBLoginAccepted'] === 1
            && (int)$row['attempts'] === 1;
        if (in_array($row['state'], ['awaiting_node_a','demo_infected','awaiting_node_b'], true) || $recoverPrematureFinalization) {
            $stmt = $conn->prepare("SELECT t.syslogThreatId,COALESCE(t.confirmed_unit_id,t.unit_id,(SELECT hr.remoteUnitId FROM hackReport hr WHERE hr.ip=t.src_ip AND hr.port=t.src_port AND hr.ownerConfirmedTime IS NOT NULL AND hr.remoteUnitId IS NOT NULL AND COALESCE(hr.lastSeen,hr.created)>=s.created ORDER BY hr.reportId DESC LIMIT 1)) resolvedUnitId FROM syslogThreat t JOIN demoSshSession s ON s.demoSshSessionId=? WHERE t.dst_ip=INET_ATON(?) AND t.dst_port=? AND t.is_attack<>0 AND t.created>=s.created AND (t.src_ip=s.sourceIp OR (s.unitId IS NOT NULL AND COALESCE(t.confirmed_unit_id,t.unit_id,(SELECT hr2.remoteUnitId FROM hackReport hr2 WHERE hr2.ip=t.src_ip AND hr2.port=t.src_port AND hr2.ownerConfirmedTime IS NOT NULL AND hr2.remoteUnitId IS NOT NULL AND COALESCE(hr2.lastSeen,hr2.created)>=s.created ORDER BY hr2.reportId DESC LIMIT 1))=s.unitId)) ORDER BY t.syslogThreatId DESC LIMIT 1");
            $stmt->bind_param('isi', $sessionId, $row['node_a'], $row['nodeAPort']);
            $stmt->execute();
            $nodeAEvidence = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Registration sees the standard gateway's NetBird address, so the
            // session may not have a unit yet. The normal Node A report and
            // gateway confession resolve the actual unit behind NAT. Bind that
            // confessed unit to this session before looking for its infection
            // or correlating the later Node B tuple.
            if ($row['unitId'] === null && $nodeAEvidence && $nodeAEvidence['resolvedUnitId'] !== null) {
                $resolvedNodeAUnitId = (int)$nodeAEvidence['resolvedUnitId'];
                $stmt = $conn->prepare("UPDATE demoSshSession SET unitId=?,lastSeen=NOW() WHERE demoSshSessionId=? AND unitId IS NULL");
                $stmt->bind_param('ii', $resolvedNodeAUnitId, $sessionId);
                $stmt->execute();
                $stmt->close();
                $row['unitId'] = $resolvedNodeAUnitId;
            }

            $stmt = $conn->prepare("SELECT hr.reportId,hr.remoteUnitId FROM hackReport hr JOIN demoSshSession s ON s.demoSshSessionId=? JOIN demoSshSetup d ON d.demoSshSetupId=s.demoSshSetupId JOIN demoSshNodeB n ON n.demoSshNodeBId=s.demoSshNodeBId WHERE hr.ip=s.sourceIp AND hr.port IN ((SELECT src_port FROM syslogThreat WHERE syslogThreatId=s.nodeAEvidenceId),s.nodeBSourcePort) AND hr.sentByIp IN (d.nodeAIp,n.ip) AND hr.ownerConfirmedTime IS NOT NULL AND COALESCE(hr.lastSeen,hr.created)>=s.created ORDER BY COALESCE(hr.lastSeen,hr.created) DESC,hr.reportId DESC LIMIT 1");
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $gatewayConfirmation = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $loginAccepted = isset($row['nodeBLoginAccepted']) && (int)$row['nodeBLoginAccepted'] === 1;
            $nextState = $row['state'];
            if ($loginAccepted && (int)$row['attempts'] === 1 && $nodeAEvidence && $gatewayConfirmation) {
                $nextState = 'cleared';
            } elseif ($nodeAEvidence && $gatewayConfirmation) {
                $nextState = 'awaiting_node_b';
            } elseif ($nodeAEvidence || $gatewayConfirmation) {
                $nextState = 'demo_infected';
            }
            $nodeAEvidenceId = $nodeAEvidence ? (int)$nodeAEvidence['syslogThreatId'] : null;
            if ($nextState !== $row['state'] || ($nodeAEvidenceId && empty($row['nodeAEvidenceId']))) {
                $previousState = (string)$row['state'];
                $stmt = $conn->prepare("UPDATE demoSshSession SET state=?,nodeAEvidenceId=COALESCE(nodeAEvidenceId,?),completed=IF(?='cleared',NOW(),completed),lastSeen=NOW() WHERE demoSshSessionId=?");
                $stmt->bind_param('sisi', $nextState, $nodeAEvidenceId, $nextState, $sessionId);
                $stmt->execute();
                $stmt->close();
                if ($nextState !== $previousState) {
                    $details = $nextState === 'cleared'
                        ? 'validated first-attempt sequence after gateway confirmation'
                        : ($nextState === 'awaiting_node_b'
                        ? 'Node A rejection and unit infection observed'
                        : ($nodeAEvidence ? 'Node A rejection observed; awaiting gateway confirmation' : 'Gateway confirmation observed; awaiting Node A report'));
                    $eventType = $nextState === 'cleared' ? 'cleared' : 'node_a_observed';
                    $eventEvidenceId = $nextState === 'cleared' && !empty($row['nodeBEvidenceId']) ? (int)$row['nodeBEvidenceId'] : $nodeAEvidenceId;
                    $stmt = $conn->prepare("INSERT INTO demoSshEvent(demoSshSessionId,eventType,sourceIp,syslogThreatId,details) VALUES(?,?,INET_ATON(?),?,?)");
                    $stmt->bind_param('issis', $sessionId, $eventType, $sender, $eventEvidenceId, $details);
                    $stmt->execute();
                    $stmt->close();
                    if ($nextState === 'cleared') {
                        $status = 'DEMO:SSH session ' . $sessionId . ': validated; gateway release required';
                        $stmt = $conn->prepare("UPDATE hackReport SET status=?,lastSeen=NOW() WHERE reportId=?");
                        $stmt->bind_param('si', $status, $gatewayConfirmation['reportId']);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
                $row['state'] = $nextState;
                if ($nodeAEvidenceId) $row['nodeAEvidenceId'] = $nodeAEvidenceId;
            }
            $row['demoInfectionObserved'] = $gatewayConfirmation ? 1 : 0;
            $row['progressMessage'] = $loginAccepted && !$gatewayConfirmation
                ? 'Node B login accepted; waiting for gateway confirmation'
                : ($nextState === 'cleared'
                    ? 'First-attempt sequence validated; gateway release required'
                    : ($nextState === 'awaiting_node_b'
                ? 'Node A and gateway reports received; continue with Node B'
                : ($nextState === 'demo_infected'
                    ? ($nodeAEvidence ? 'Node A report received; waiting for gateway confirmation' : 'Gateway confirmation received; waiting for Node A report')
                    : 'Waiting for Node A rejection report')));
        }
        // MySQL created the expiry using NOW(), so MySQL must also decide
        // whether it has passed. Parsing its timezone-less timestamp in PHP
        // made sessions expire immediately when PHP and MySQL timezones differed.
        if ((int)$row['secondsRemaining'] <= 0 && in_array($row['state'], ['awaiting_node_a','demo_infected','awaiting_node_b'], true)) {
            $stmt = $conn->prepare("UPDATE demoSshSession SET state='expired',completed=NOW(),lastSeen=NOW() WHERE demoSshSessionId=?");
            $stmt->bind_param('i', $sessionId); $stmt->execute(); $stmt->close();
            $row['state'] = 'expired';
            $row['secondsRemaining'] = 0;
        }
        $row['operationalWarning'] = demoOperationalWarning($conn, ['gateway' => $sender, 'Node A' => $row['node_a'], 'Node B' => $row['node_b']]);
        demoReply(200, ['ok' => true, 'session' => demoPublicSession($row)]);
    }

    demoReply(400, ['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('appDemoSshSession.php failed: ' . $e->getMessage());
    demoReply(500, ['ok' => false, 'error' => 'Demo service unavailable']);
}
