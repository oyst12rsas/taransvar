<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $conn = getConnection();
    $name = gethostname() ?: 'TaraSec node';

    try {
        $result = $conn->query('SELECT nickname FROM setup LIMIT 1');
        if ($row = $result->fetch_assoc()) {
            $candidate = trim((string)($row['nickname'] ?? ''));
            if ($candidate !== '') $name = $candidate;
        }
        $result->free();
    } catch (Throwable $ignored) {
        // Older installations may not have setup.nickname.
    }

    $conn->close();
    echo json_encode([
        'ok' => true,
        'name' => $name,
        'role' => 'tarasec-node',
        'server_time' => gmdate('c')
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('appNode.php: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'node_identity_unavailable']);
}
