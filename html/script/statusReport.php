<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

include '../dbfunc.php';
include 'tagged.php';

$sender = getSenderIp();
$status = file_get_contents('php://input');

if ($status === false || $status === '') {
    http_response_code(400);
    exit('Missing status report');
}

if (strlen($status) > 1_000_000) {
    http_response_code(413);
    exit('Status report too large');
}

json_decode($status);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit('Invalid JSON: ' . json_last_error_msg());
}

$conn = getConnection();

try {
    $conn->begin_transaction();

    $sql = '
        UPDATE partnerRouter
        SET status = ?, partnerStatusReceived = NOW()
        WHERE ip = INET_ATON(?)
    ';

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    $stmt->bind_param('ss', $status, $sender);
    $stmt->execute();
    $stmt->close();

    $sql = '
        INSERT INTO partnerRouterStatusLog (ip, status)
        VALUES (INET_ATON(?), ?)
    ';

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    $stmt->bind_param('ss', $sender, $status);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    echo 'ok';
} catch (Throwable $e) {
    $conn->rollback();

    error_log(
        sprintf(
            'Status update failed: sender=%s error=%s',
            $sender,
            $e->getMessage()
        )
    );

    http_response_code(500);
    echo 'error';
} finally {
    $conn->close();
}
