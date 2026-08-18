<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';
include '../taraLib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function appInfectionFail(int $status, string $message, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

function normaliseIpv4(string $ip): string
{
    if (strncasecmp($ip, '::ffff:', 7) === 0) {
        $ip = substr($ip, 7);
    }
    return $ip;
}

function appCpuCount(): int
{
    $count = 0;
    if (is_readable('/proc/cpuinfo')) {
        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuInfo !== false) {
            preg_match_all('/^processor\s*:/m', $cpuInfo, $matches);
            $count = count($matches[0]);
        }
    }
    return max(1, $count);
}

$sender = normaliseIpv4(getSenderIp());
$senderPort = (int)($_SERVER['REMOTE_PORT'] ?? 0);

if (!filter_var($sender, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    appInfectionFail(400, 'Unable to determine client IPv4 address');
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'status')));
if (!in_array($action, ['status', 'clear'], true)) {
    appInfectionFail(400, 'Invalid action');
}
if ($action === 'clear' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    appInfectionFail(405, 'Clear requires POST');
}

// Automatic polling is intentionally lightweight and may be rejected early
// when the gateway is already saturated. Manual status/clear operations are
// still allowed so an owner can recover/manage the unit under load.
$polling = $action === 'status' && ((string)($_REQUEST['poll'] ?? '0') === '1');
if ($polling) {
    $loads = sys_getloadavg();
    $load1 = isset($loads[0]) ? (float)$loads[0] : 0.0;
    $cpus = appCpuCount();
    $normalisedLoad = $load1 / $cpus;

    if ($normalisedLoad > 1.0) {
        appInfectionFail(503, 'gateway_busy', [
            'message' => 'Gateway is temporarily busy',
            'load1' => round($load1, 2),
            'cpus' => $cpus,
            'normalised_load' => round($normalisedLoad, 2),
            'retry_after_ms' => 2000
        ]);
    }
}

try {
    $conn = getConnection();

    /*
     * Resolve the unit behind this connection using the same unitPort data
     * used by config_update.php?f=unitIp. The port is especially useful when
     * several units share the gateway/NAT address.
     */
    $unitId = 0;
    $unitIp = '';
    $unitDataAge = -1;

    if ($senderPort > 0) {
        $sql = "SELECT inet_ntoa(ipAddress) AS ip,
                       unitId,
                       TIMESTAMPDIFF(SECOND, lastSeen, NOW()) AS seconds_since
                FROM unitPort
                WHERE port = ?
                ORDER BY lastSeen DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $senderPort);
        $stmt->execute();
        $unitRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($unitRow) {
            $unitId = (int)($unitRow['unitId'] ?? 0);
            $unitIp = (string)($unitRow['ip'] ?? '');
            $unitDataAge = (int)($unitRow['seconds_since'] ?? -1);
        }
    }

    /*
     * referenceId is the transport/API correlation value. For the current
     * implementation it may be derived from the local infectionId, but the
     * database key itself is deliberately not exposed as infectionId.
     *
     * Prefer unitId when the infection row already carries it. Older/current
     * infection rows may still only be correlated by IP, so fall back to the
     * unit IP resolved from unitPort, and finally to the directly observed IP.
     */
    $referenceId = 0;
    $infectionRow = null;

    if ($unitId > 0) {
        $sql = "SELECT infectionId
                FROM internalInfections
                WHERE unitId = ?
                ORDER BY infectionId DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $unitId);
        $stmt->execute();
        $infectionRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$infectionRow && filter_var($unitIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $sql = "SELECT infectionId
                FROM internalInfections
                WHERE ip = inet_aton(?)
                ORDER BY infectionId DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $unitIp);
        $stmt->execute();
        $infectionRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$infectionRow) {
        $sql = "SELECT infectionId
                FROM internalInfections
                WHERE ip = inet_aton(?)
                ORDER BY infectionId DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $sender);
        $stmt->execute();
        $infectionRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($infectionRow) {
        $referenceId = (int)($infectionRow['infectionId'] ?? 0);
    }

    $cleared = 0;
    if ($action === 'clear') {
        /*
         * Prefer the resolved unitId, but do not assume every existing
         * internalInfections row has already been populated with unitId.
         * If nothing was cleared, fall back to the resolved unit IP and then
         * to the directly observed sender IP.
         */
        if ($unitId > 0) {
            $sql = "UPDATE internalInfections
                    SET active = b'0', lastSeen = NOW()
                    WHERE unitId = ? AND active = b'1'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $unitId);
            $stmt->execute();
            $cleared = $stmt->affected_rows;
            $stmt->close();
        }

        if ($cleared === 0 && filter_var($unitIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $sql = "UPDATE internalInfections
                    SET active = b'0', lastSeen = NOW()
                    WHERE ip = inet_aton(?) AND active = b'1'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $unitIp);
            $stmt->execute();
            $cleared = $stmt->affected_rows;
            $stmt->close();
        }

        if ($cleared === 0 && $unitIp !== $sender) {
            $sql = "UPDATE internalInfections
                    SET active = b'0', lastSeen = NOW()
                    WHERE ip = inet_aton(?) AND active = b'1'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $sender);
            $stmt->execute();
            $cleared = $stmt->affected_rows;
            $stmt->close();
        }
    }

    $conn->close();

    /*
     * getTagData() remains the canonical TaraSec assessment routine. Manual
     * status checks may briefly wait for the traffic/tag record to arrive.
     * Automatic polling never waits: it returns what TaraSec knows right now.
     */
    $tagData = [];
    $attempts = ($action === 'status' && !$polling) ? 6 : 1;
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        $tagData = getTagData();
        if ((int)($tagData['trafficSecondsSince'] ?? -1) >= 0) {
            break;
        }
        if ($attempt + 1 < $attempts) {
            usleep(500000);
        }
    }

    $severity = (int)($tagData['severity'] ?? 0);
    $trafficAge = (int)($tagData['trafficSecondsSince'] ?? -1);
    $hackAge = (int)($tagData['hackReportSecondsSince'] ?? -1);
    $infectionSeverity = (int)($tagData['infectionSeverity'] ?? -1);

    if ($trafficAge >= 0 && $trafficAge < 45) {
        $source = 'traffic';
    } elseif ($hackAge >= 0) {
        $source = 'hackReport';
    } elseif ($infectionSeverity >= 0) {
        $source = 'internalInfections';
    } else {
        $source = 'none';
    }

    echo json_encode([
        'ok' => true,
        'client_ip' => $sender,
        'client_port' => $senderPort,
        'unit_ip' => $unitIp,
        'unitId' => $unitId ?: null,
        'unit_data_age' => $unitDataAge,
        'referenceId' => $referenceId ?: null,
        'infected' => $severity > 1,
        'severity' => $severity,
        'source' => $source,
        'trafficSeverity' => (int)($tagData['trafficSeverity'] ?? 0),
        'trafficSecondsSince' => $trafficAge,
        'hackReportSeverity' => (int)($tagData['hackReportSeverity'] ?? 0),
        'hackReportSecondsSince' => $hackAge,
        'infectionSeverity' => $infectionSeverity,
        'infectionDisabled' => (int)($tagData['infectionDisabled'] ?? 0),
        'cleared' => $cleared,
        'polling' => $polling,
        'server_time' => gmdate('c')
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('appInfection.php failed for ' . $sender . ':' . $senderPort . ': ' . $e->getMessage());
    appInfectionFail(500, 'Database failure');
}
