<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

function statusPeerIp()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : '';
    if (strncasecmp($ip, '::ffff:', 7) === 0) {
        $ip = substr($ip, 7);
    }
    return $ip;
}

$sender = statusPeerIp();
$status = file_get_contents('php://input');

if (!filter_var($sender, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    http_response_code(403);
    exit('Untrusted sender');
}

if ($status === false || $status === '') {
    http_response_code(400);
    exit('Missing status report (json)');
}

if (strlen($status) > 1_000_000) {
    http_response_code(413);
    exit('Status report (json) too large');
}

json_decode($status);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit('Invalid JSON: ' . json_last_error_msg());
}

$conn = getConnection();

try {
    $conn->begin_transaction();

    // Status is a control-plane message. Only known routers may create/update status.
    $stmt = $conn->prepare('SELECT 1 FROM partnerRouter WHERE ip = INET_ATON(?) LIMIT 1');
    $stmt->bind_param('s', $sender);
    $stmt->execute();
    $knownRouter = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();

    if (!$knownRouter) {
        $conn->rollback();
        http_response_code(403);
        echo 'unregistered router';
        return;
    }

    $sql = '
        UPDATE partnerRouter
        SET status = ?, partnerStatusReceived = NOW()
        WHERE ip = INET_ATON(?)
    ';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $status, $sender);
    $stmt->execute();
    $stmt->close();

    $sql = '
        INSERT INTO partnerRouterStatusLog (ip, status, created)
        VALUES (INET_ATON(?), ?, now())
    ';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $sender, $status);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';

} catch (Throwable $e) {
    if (isset($conn)) {
        $conn->rollback();
    }

    error_log(
        "Status update failed\n"
        . "Sender: " . $sender . "\n"
        . "Error: " . $e->getMessage() . "\n"
        . "File: " . $e->getFile() . "\n"
        . "Line: " . $e->getLine() . "\n"
    );

    http_response_code(500);
    echo 'error';

} finally {
    $conn->close();
}
