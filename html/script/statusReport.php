<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';
include 'tagged.php';

$sender = getSenderIp();
$status = file_get_contents('php://input');

if ($status === false || $status === '') {
    http_response_code(400);
    exit('Missing status report (json)');
}

if (strlen($status) > 1_000_000) {
    http_response_code(413);
    exit('Status report (json) too large');
}

//print "In statusReport.php. Received Json: $status\n\n";

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

	//Note! created doesn't have default value by now but soon will on all computers... Include created = now() for a while
    $sql = '
        INSERT INTO partnerRouterStatusLog (ip, status, created)
        VALUES (INET_ATON(?), ?, now())
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
    if (isset($conn)) {
        $conn->rollback();
    }

    $msg =
        "Status update failed\n"
        . "Sender: " . $sender . "\n"
        . "Error: " . $e->getMessage() . "\n"
        . "File: " . $e->getFile() . "\n"
        . "Line: " . $e->getLine() . "\n";


	/*	Or less descriptive
		    $msg = sprintf(
            'Status update failed: sender=%s error=%s',
            $sender,
            $e->getMessage()
        );
	*/



    error_log($msg);

    http_response_code(500);
    
	//header('Content-Type: text/plain');
    //echo $msg;

	//Or just print:
    echo 'error';

} finally {
    $conn->close();
}
