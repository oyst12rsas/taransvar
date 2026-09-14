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
          ORDER BY (active=b'1' AND severity>1) DESC,
                   COALESCE(lastSeen,inserted) DESC,
                   infectionId DESC
          LIMIT 1"
    );
    $stmt->bind_param('s', $sender);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    $active = $row ? ((int)$row['active'] === 1) : false;
    $severity = $row ? (int)$row['severity'] : 0;
    $why = $row ? (string)$row['why'] : '';
    $infected = $active && $severity > 1;
    $demoResetAvailable = $infected && str_starts_with($why, 'DEMO:');

    echo json_encode([
        'ok' => true,
        'eligible_for_demo' => !$infected,
        'infected' => $infected,
        'severity' => $active ? $severity : 0,
        'source' => 'internalInfections',
        'gateway' => gethostname() ?: 'TaraSec gateway',
        'client_ip' => $sender,
        'client_port' => (int)($_SERVER['REMOTE_PORT'] ?? 0),
        'infectionId' => $row ? (int)$row['infectionId'] : 0,
        'active' => $active,
        'why' => $why,
        'demo_reset_available' => $demoResetAvailable,
        'next' => !$infected ? 'demo' : ($demoResetAvailable ? 'reset_demo' : 'remediation'),
        'message' => !$infected
            ? 'This unit may start the demonstration'
            : ($demoResetAvailable
                ? 'This unit has demo infection state on the current gateway'
                : 'This unit needs a security review before the demonstration can start'),
        'lastSeen' => $row ? (string)$row['lastSeen'] : '',
        'server_time' => gmdate('c')
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('appLocalInfection.php failed for ' . $sender . ': ' . $e->getMessage());
    localInfectionFail(500, 'Local infection lookup failed');
}
