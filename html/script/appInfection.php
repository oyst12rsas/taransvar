<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';
include 'getSenderIp.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function appInfectionFail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

$sender = getSenderIp();
if (strncasecmp($sender, '::ffff:', 7) === 0) {
    $sender = substr($sender, 7);
}
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

    if ($action === 'clear') {
        // A client may only clear active infection rows belonging to the same
        // source IP the gateway sees on this TCP connection.
        $sql = "UPDATE internalInfections
                SET active = b'0', lastSeen = NOW()
                WHERE ip = INET_ATON(?) AND active = b'1'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $sender);
        $stmt->execute();
        $cleared = $stmt->affected_rows;
        $stmt->close();
    } else {
        $cleared = 0;
    }

    $sql = "SELECT
                COUNT(*) AS infection_count,
                COALESCE(MAX(severity), 0) AS severity,
                MAX(lastSeen) AS last_seen
            FROM internalInfections
            WHERE ip = INET_ATON(?) AND active = b'1'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $sender);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    $count = (int)($row['infection_count'] ?? 0);
    $severity = (int)($row['severity'] ?? 0);

    echo json_encode([
        'ok' => true,
        'client_ip' => $sender,
        'infected' => $count > 0,
        'severity' => $severity,
        'infection_count' => $count,
        'last_seen' => $row['last_seen'] ?? null,
        'cleared' => $cleared,
        'server_time' => gmdate('c')
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('appInfection.php failed for ' . $sender . ': ' . $e->getMessage());
    appInfectionFail(500, 'Database failure');
}
