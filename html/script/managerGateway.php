<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function gatewayReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function startManagerSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

startManagerSession();

if (empty($_SESSION['tarasec_manager_authenticated'])) {
    gatewayReply(401, ['ok' => false, 'error' => 'manager_login_required']);
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'status')));
if ($action !== 'status') {
    gatewayReply(400, ['ok' => false, 'error' => 'invalid_action']);
}

try {
    $conn = getConnection();

    $nickname = 'TaraSec gateway';
    try {
        $result = $conn->query('SELECT nickname FROM setup LIMIT 1');
        if ($row = $result->fetch_assoc()) {
            $candidate = trim((string)($row['nickname'] ?? ''));
            if ($candidate !== '') $nickname = $candidate;
        }
        $result->free();
    } catch (Throwable $ignored) {
        // Older installations may not yet expose a nickname. Status should still work.
    }

    $managerEmail = (string)($_SESSION['tarasec_manager_email'] ?? '');
    $requestId = (int)($_SESSION['tarasec_manager_request_id'] ?? 0);

    // Re-check that this manager still exists and is active. This makes revocation
    // effective for already logged-in app sessions on the next status refresh.
    $stmt = $conn->prepare("SELECT active,rejectedTime,expires FROM managerRequest WHERE managerRequestId=? AND email=? LIMIT 1");
    $stmt->bind_param('is', $requestId, $managerEmail);
    $stmt->execute();
    $manager = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stillActive = $manager
        && !empty($manager['active'])
        && empty($manager['rejectedTime'])
        && (empty($manager['expires']) || strtotime((string)$manager['expires']) > time());

    if (!$stillActive) {
        $_SESSION = [];
        session_destroy();
        $conn->close();
        gatewayReply(401, ['ok' => false, 'error' => 'manager_access_revoked']);
    }

    $conn->close();

    gatewayReply(200, [
        'ok' => true,
        'gateway' => [
            'name' => $nickname,
            'reachable' => true,
            'serverTime' => gmdate('c'),
        ],
        'manager' => [
            'email' => $managerEmail,
            'requestId' => $requestId,
        ],
        'capabilities' => [
            'status' => true,
            'threats' => false,
            'units' => false,
            'notifications' => false,
        ],
    ]);
} catch (Throwable $e) {
    error_log('managerGateway.php: ' . $e->getMessage());
    gatewayReply(503, ['ok' => false, 'error' => 'gateway_status_unavailable']);
}
