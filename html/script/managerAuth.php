<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Cache-Control: no-store');

function managerReply(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function managerHtmlReply(int $status, string $title, string $message, bool $success = false): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $accent = $success ? '#16784b' : '#9b2c2c';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . $safeTitle . '</title>'
       . '<style>body{margin:0;background:#f5f7f8;color:#172126;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}'
       . '.card{max-width:620px;margin:10vh auto;padding:32px;border-radius:14px;background:#fff;box-shadow:0 8px 30px rgba(0,0,0,.08)}'
       . 'h1{margin:0 0 18px;color:' . $accent . ';font-size:1.8rem}p{line-height:1.55}.brand{font-weight:700;margin-bottom:22px}</style>'
       . '</head><body><main class="card"><div class="brand">TaraSec</div><h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p>';
    if ($success) {
        echo '<p>A gateway administrator must still approve the request before manager access becomes active.</p>'
           . '<p>You can now return to the TaraSec app and refresh the approval status.</p>';
    }
    echo '</main></body></html>';
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
            $conn->close();
            managerHtmlReply(400, 'Verification link is incomplete', 'The email verification token is missing. Please use the complete link from your TaraSec email.');
        }
        $hash = hash('sha256', $token);
        $stmt = $conn->prepare("SELECT managerRequestId,email,emailVerifiedTime FROM managerRequest WHERE emailVerifyTokenHash=? AND rejectedTime IS NULL AND (expires IS NULL OR expires>NOW()) LIMIT 1");
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            $conn->close();
            managerHtmlReply(404, 'Verification link is no longer valid', 'This verification link was not found, has expired, or belongs to a rejected request.');
        }
        if (empty($row['emailVerifiedTime'])) {
            $stmt = $conn->prepare("UPDATE managerRequest SET emailVerifiedTime=NOW(), active=IF(gatewayApprovedTime IS NOT NULL AND credentialHash IS NOT NULL,b'1',active) WHERE managerRequestId=?");
            $requestId = (int)$row['managerRequestId'];
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $stmt->close();
        }
        $conn->close();
        managerHtmlReply(200, 'Email address confirmed', 'Your email address has been successfully verified for TaraSec manager access.', true);
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
    if ($action === 'verify_email') {
        managerHtmlReply(503, 'Verification temporarily unavailable', 'The gateway could not complete email verification right now. Please try the link again shortly.');
    }
    managerReply(503, ['ok' => false, 'error' => 'manager_auth_unavailable']);
}
