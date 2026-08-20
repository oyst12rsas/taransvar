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

$incoming = json_decode($status, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($incoming)) {
    http_response_code(400);
    exit('Invalid JSON: ' . json_last_error_msg());
}

$partialStatus = !empty($incoming['_partialStatus']);
unset($incoming['_partialStatus']);

$conn = getConnection();

try {
    $conn->begin_transaction();

    // Status is a control-plane message. Only known routers may create/update status.
    // Fetch the current JSON as well so small subsystem reports can be merged without
    // replacing the normal full gateway status sent by crontasks.pl.
    $stmt = $conn->prepare('SELECT status FROM partnerRouter WHERE ip = INET_ATON(?) LIMIT 1 FOR UPDATE');
    $stmt->bind_param('s', $sender);
    $stmt->execute();
    $routerRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$routerRow) {
        $conn->rollback();
        http_response_code(403);
        echo 'unregistered router';
        return;
    }

    $current = json_decode((string)($routerRow['status'] ?? ''), true);
    if (!is_array($current)) {
        $current = [];
    }

    if ($partialStatus) {
        // Subsystem updates (currently mail health) enrich the latest status JSON,
        // but they are not proof that the normal server heartbeat is running.
        $incoming = array_replace_recursive($current, $incoming);
    } else {
        // Mail health is refreshed independently by manager_requests.pl. Preserve
        // its latest values when the normal full status report does not carry them.
        foreach (['mailCfg','mailRelay','mailSend','mailChecked','mailErr'] as $key) {
            if (!array_key_exists($key, $incoming) && array_key_exists($key, $current)) {
                $incoming[$key] = $current[$key];
            }
        }
    }

    $status = json_encode($incoming, JSON_UNESCAPED_SLASHES);
    if ($status === false) {
        throw new RuntimeException('Unable to encode merged status JSON');
    }

    if ($partialStatus) {
        // Do not advance partnerStatusReceived for partial subsystem reports. The
        // timestamp must continue to mean "last complete crontasks.pl heartbeat".
        $sql = '
            UPDATE partnerRouter
            SET status = ?
            WHERE ip = INET_ATON(?)
        ';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $status, $sender);
        $stmt->execute();
        $stmt->close();
    } else {
        $sql = '
            UPDATE partnerRouter
            SET status = ?, partnerStatusReceived = NOW()
            WHERE ip = INET_ATON(?)
        ';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $status, $sender);
        $stmt->execute();
        $stmt->close();

        // partnerRouterStatusLog is the history of complete server heartbeats.
        // Partial subsystem updates are already retained in partnerRouter.status
        // and have their own timestamps (for example mailChecked).
        $sql = '
            INSERT INTO partnerRouterStatusLog (ip, status, created)
            VALUES (INET_ATON(?), ?, now())
        ';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $sender, $status);
        $stmt->execute();
        $stmt->close();
    }

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
