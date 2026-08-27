<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';
include '../taraLib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function localInfectionFail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

$sender = getSenderIp();
if (filter_var($sender, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
    localInfectionFail(400, 'Unable to identify calling IPv4 client');
}

try {
    $conn = getConnection();
    $stmt = $conn->prepare(
        "SELECT infectionId, severity, CAST(active AS UNSIGNED) AS active, why, lastSeen
           FROM internalInfections
          WHERE ip = INET_ATON(?)
          ORDER BY infectionId DESC
          LIMIT 1"
    );
    $stmt->bind_param('s', $sender);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    $active = $row ? ((int)$row['active'] === 1) : false;
    $severity = $row ? (int)$row['severity'] : 0;

    echo json_encode([
        'ok' => true,
        'infected' => $active && $severity > 1,
        'severity' => $active ? $severity : 0,
        'source' => 'internalInfections',
        'client_ip' => $sender,
        'client_port' => (int)($_SERVER['REMOTE_PORT'] ?? 0),
        'infectionId' => $row ? (int)$row['infectionId'] : 0,
        'active' => $active,
        'why' => $row ? (string)$row['why'] : '',
        'lastSeen' => $row ? (string)$row['lastSeen'] : '',
        'server_time' => gmdate('c')
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('appLocalInfection.php failed for ' . $sender . ': ' . $e->getMessage());
    localInfectionFail(500, 'Local infection lookup failed');
}
