<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function resendReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    resendReply(405, ['ok' => false, 'error' => 'post_required']);
}

$requestId = (int)($_POST['requestId'] ?? 0);
$requestToken = trim((string)($_POST['requestToken'] ?? ''));
if ($requestId <= 0 || $requestToken === '') {
    resendReply(400, ['ok' => false, 'error' => 'missing_request']);
}

try {
    $conn = getConnection();
    $requestTokenHash = hash('sha256', $requestToken);

    $stmt = $conn->prepare(
        "SELECT managerRequestId,email,emailVerifiedTime,rejectedTime
           FROM managerRequest
          WHERE managerRequestId=?
            AND requestTokenHash=?
            AND (expires IS NULL OR expires>NOW())
          LIMIT 1"
    );
    $stmt->bind_param('is', $requestId, $requestTokenHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $conn->close();
        resendReply(404, ['ok' => false, 'error' => 'request_not_found']);
    }
    if (!empty($row['rejectedTime'])) {
        $conn->close();
        resendReply(409, ['ok' => false, 'error' => 'request_rejected']);
    }
    if (!empty($row['emailVerifiedTime'])) {
        $conn->close();
        resendReply(409, ['ok' => false, 'error' => 'email_already_verified']);
    }

    // Rotate the email token so any earlier verification link becomes invalid.
    // manager_requests.pl sees emailSentTime=NULL and delivers the new token.
    $emailToken = bin2hex(random_bytes(32));
    $emailTokenHash = hash('sha256', $emailToken);
    $stmt = $conn->prepare(
        "UPDATE managerRequest
            SET emailVerifyTokenPlain=?,
                emailVerifyTokenHash=?,
                emailSentTime=NULL
          WHERE managerRequestId=?
            AND requestTokenHash=?
            AND emailVerifiedTime IS NULL
            AND rejectedTime IS NULL"
    );
    $stmt->bind_param('ssis', $emailToken, $emailTokenHash, $requestId, $requestTokenHash);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    if ($changed < 1) {
        resendReply(409, ['ok' => false, 'error' => 'resend_not_available']);
    }

    resendReply(202, [
        'ok' => true,
        'queued' => true,
        'requestId' => $requestId,
        'email' => (string)$row['email'],
        'message' => 'Verification email queued for resend.'
    ]);
} catch (Throwable $e) {
    error_log('managerResend.php: ' . $e->getMessage());
    resendReply(503, ['ok' => false, 'error' => 'manager_auth_unavailable']);
}
