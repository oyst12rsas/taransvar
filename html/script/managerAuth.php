<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function managerReply(int $status, array $data): never
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

function requestState(array $row): array
{
    $emailVerified = !empty($row['emailVerifiedTime']);
    $gatewayApproved = !empty($row['gatewayApprovedTime']);
    $credentialReady = !empty($row['credentialHash']);
    $rejected = !empty($row['rejectedTime']);
    $active = !$rejected && $emailVerified && $gatewayApproved && $credentialReady;
    return [
        'requestId' => (int)$row['managerRequestId'],
        'email' => (string)$row['email'],
        'credentialReady' => $credentialReady,
        'emailVerified' => $emailVerified,
        'gatewayApproved' => $gatewayApproved,
        'rejected' => $rejected,
        'active' => $active,
    ];
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'session')));

if ($action === 'session') {
    startManagerSession();
    managerReply(200, [
        'ok' => true,
        'authenticated' => !empty($_SESSION['tarasec_manager_authenticated']),
        'manager' => empty($_SESSION['tarasec_manager_authenticated']) ? null : [
            'email' => (string)($_SESSION['tarasec_manager_email'] ?? ''),
            'requestId' => (int)($_SESSION['tarasec_manager_request_id'] ?? 0),
            'authenticated_at' => (string)($_SESSION['tarasec_manager_authenticated_at'] ?? ''),
        ],
    ]);
}

if ($action === 'logout') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        managerReply(405, ['ok' => false, 'error' => 'logout_requires_post']);
    }
    startManagerSession();
    $_SESSION = [];
    session_destroy();
    managerReply(200, ['ok' => true, 'authenticated' => false]);
}

try {
    $conn = getConnection();

    if ($action === 'request') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            managerReply(405, ['ok' => false, 'error' => 'request_requires_post']);
        }
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            managerReply(400, ['ok' => false, 'error' => 'invalid_email']);
        }

        $requestToken = bin2hex(random_bytes(32));
        $requestTokenHash = hash('sha256', $requestToken);
        $stmt = $conn->prepare("INSERT INTO managerRequest (email, requestTokenHash, expires) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))");
        $stmt->bind_param('ss', $email, $requestTokenHash);
        $stmt->execute();
        $requestId = $stmt->insert_id;
        $stmt->close();
        $conn->close();
        managerReply(201, [
            'ok' => true,
            'requestId' => $requestId,
            'requestToken' => $requestToken,
            'email' => $email,
            'credentialReady' => false,
            'emailVerified' => false,
            'gatewayApproved' => false,
            'active' => false,
        ]);
    }

    if ($action === 'status') {
        $requestId = (int)($_REQUEST['requestId'] ?? 0);
        $requestToken = trim((string)($_REQUEST['requestToken'] ?? ''));
        if ($requestId <= 0 || $requestToken === '') {
            managerReply(400, ['ok' => false, 'error' => 'missing_request']);
        }
        $tokenHash = hash('sha256', $requestToken);
        $stmt = $conn->prepare("SELECT * FROM managerRequest WHERE managerRequestId=? AND requestTokenHash=? AND (expires IS NULL OR expires>NOW()) LIMIT 1");
        $stmt->bind_param('is', $requestId, $tokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            managerReply(404, ['ok' => false, 'error' => 'request_not_found']);
        }
        $state = requestState($row);
        if (!empty($row['credentialPlain']) && empty($row['credentialClaimedTime'])) {
            $state['credential'] = (string)$row['credentialPlain'];
        }
        $conn->close();
        managerReply(200, array_merge(['ok' => true], $state));
    }

    if ($action === 'verify_email') {
        $token = trim((string)($_REQUEST['token'] ?? ''));
        if ($token === '') {
            managerReply(400, ['ok' => false, 'error' => 'missing_token']);
        }
        $hash = hash('sha256', $token);
        $stmt = $conn->prepare("UPDATE managerRequest SET emailVerifiedTime=COALESCE(emailVerifiedTime,NOW()), active=IF(gatewayApprovedTime IS NOT NULL AND credentialHash IS NOT NULL,b'1',active) WHERE emailVerifyTokenHash=? AND rejectedTime IS NULL AND (expires IS NULL OR expires>NOW())");
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        $conn->close();
        if ($changed < 1) {
            managerReply(404, ['ok' => false, 'error' => 'verification_not_found']);
        }
        managerReply(200, ['ok' => true, 'emailVerified' => true]);
    }

    if ($action === 'login') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            managerReply(405, ['ok' => false, 'error' => 'login_requires_post']);
        }
        $key = trim((string)($_POST['key'] ?? ''));
        if ($key === '') {
            managerReply(400, ['ok' => false, 'error' => 'missing_key']);
        }
        $hash = hash('sha256', $key);
        $stmt = $conn->prepare("SELECT managerRequestId,email FROM managerRequest WHERE credentialHash=? AND active=b'1' AND rejectedTime IS NULL AND (expires IS NULL OR expires>NOW()) LIMIT 1");
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            managerReply(401, ['ok' => false, 'error' => 'manager_not_approved']);
        }
        $requestId = (int)$row['managerRequestId'];
        $stmt = $conn->prepare("UPDATE managerRequest SET lastUsedTime=NOW(), credentialClaimedTime=COALESCE(credentialClaimedTime,NOW()), credentialPlain=NULL WHERE managerRequestId=?");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $stmt->close();
        $conn->close();

        startManagerSession();
        session_regenerate_id(true);
        $_SESSION['tarasec_manager_authenticated'] = 1;
        $_SESSION['tarasec_manager_email'] = (string)$row['email'];
        $_SESSION['tarasec_manager_request_id'] = $requestId;
        $_SESSION['tarasec_manager_authenticated_at'] = gmdate('c');
        managerReply(200, [
            'ok' => true,
            'authenticated' => true,
            'manager' => [
                'email' => (string)$row['email'],
                'requestId' => $requestId,
                'authenticated_at' => $_SESSION['tarasec_manager_authenticated_at']
            ]
        ]);
    }

    $conn->close();
    managerReply(400, ['ok' => false, 'error' => 'invalid_action']);
} catch (Throwable $e) {
    error_log('managerAuth.php: ' . $e->getMessage());
    managerReply(503, ['ok' => false, 'error' => 'manager_auth_unavailable']);
}
