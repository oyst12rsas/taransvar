<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';
include '../taraLib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$sender = getSenderIp();
if (filter_var($sender, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unable to identify gateway route']);
    exit;
}

try {
    $conn = getConnection();
    $name = '';
    $recognized = false;

    if ($sender === '100.68.165.190') {
        $name = 'Standard gateway';
        $recognized = true;
    } else {
        $stmt = $conn->prepare("SELECT hostname,description FROM unit WHERE ipAddress=INET_ATON(?) ORDER BY lastSeen DESC,unitId DESC LIMIT 1");
        $stmt->bind_param('s', $sender);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $name = trim((string)($row['hostname'] ?: $row['description'] ?: 'TaraSec gateway'));
            $recognized = true;
        }
    }

    echo json_encode([
        'ok' => true,
        'gateway' => [
            'address' => $sender,
            'name' => $recognized ? $name : 'Unrecognized gateway',
            'recognized' => $recognized
        ]
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('appDemoGateway.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to identify gateway route']);
}
