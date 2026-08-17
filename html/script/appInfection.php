<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';
include '../taraLib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function appInfectionFail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function normaliseIpv4(string $ip): string
{
    if (strncasecmp($ip, '::ffff:', 7) === 0) {
        $ip = substr($ip, 7);
    }
    return $ip;
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

try {
    $conn = getConnection();

    /*
     * Resolve the unit behind this connection using the same unitPort data
     * used by config_update.php?f=unitIp.  The port is especially useful when
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
     * referenceId is the transport/API correlation value.  For the current
     * implementation it may be derived from the local infectionId, but the
     * database key itself is deliberately not exposed as infectionId.
     */
    $referenceId = 0;
    if ($unitId > 0) {
        $sql = "SELECT infectionId
                FROM internalInfections
                WHERE unitId = ?
                ORDER BY infectionId DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $unitId);
    } else {
        $sql = "SELECT infectionId
                FROM internalInfections
                WHERE ip = inet_aton(?)
                ORDER BY infectionId DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $sender);
    }
    $stmt->execute();
    $infectionRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($infectionRow) {
        $referenceId = (int)($infectionRow['infectionId'] ?? 0);
    }

    $cleared = 0;
    if ($action === 'clear') {
        // Clear only the unit resolved from this connection.  Fall back to the
        // directly observed sender IP when no recent unitPort mapping exists.
        if ($unitId > 0) {
            $sql = "UPDATE internalInfections
                    SET active = b'0', lastSeen = NOW()
                    WHERE unitId = ? AND active = b'1'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $unitId);
        } else {
            $sql = "UPDATE internalInfections
                    SET active = b'0', lastSeen = NOW()
                    WHERE ip = inet_aton(?) AND active = b'1'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $sender);
        }
        $stmt->execute();
        $cleared = $stmt->affected_rows;
        $stmt->close();
    }

    $conn->close();

    /*
     * getTagData() remains the canonical TaraSec assessment routine.  Tagged
     * traffic can arrive a little after the HTTP request, so for a status
     * request give it a short chance to appear before returning the fallback
     * hackReport/internalInfections assessment.
     */
    $tagData = [];
    $attempts = ($action === 'status') ? 6 : 1;
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
        'server_time' => gmdate('c')
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('appInfection.php failed for ' . $sender . ':' . $senderPort . ': ' . $e->getMessage());
    appInfectionFail(500, 'Database failure');
}
