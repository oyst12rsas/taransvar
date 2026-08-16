<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';
include '../taraLib.php';

header('Content-Type: text/plain; charset=utf-8');

function confessionFail(int $status, string $message): void
{
    http_response_code($status);
    echo 'error: ' . $message;
    exit;
}

$sender = getSenderIp();
if (strncasecmp($sender, '::ffff:', 7) === 0) {
    $sender = substr($sender, 7);
}
if (!filter_var($sender, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    confessionFail(403, 'invalid sender');
}
if (!isset($_GET['ip'], $_GET['port'])) {
    confessionFail(400, 'missing ip or port');
}

$ip = trim((string)$_GET['ip']);
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    confessionFail(400, 'invalid ip');
}
if ($sender !== $ip) {
    confessionFail(403, 'confession sender does not match reported source');
}

$port = filter_var($_GET['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
if ($port === false) {
    confessionFail(400, 'invalid port');
}

// A network may be able to confirm ownership before it has resolved the exact
// owner-generated unit ID. Missing/0 ourid therefore means "confirmed, unit unknown".
$unitId = null;
if (isset($_GET['ourid']) && $_GET['ourid'] !== '' && $_GET['ourid'] !== '0') {
    $validatedUnitId = filter_var($_GET['ourid'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($validatedUnitId === false) {
        confessionFail(400, 'invalid unit id');
    }
    $unitId = (int)$validatedUnitId;
}

try {
    $conn = getConnection();

    // The victim report should normally already exist. Match only a very recent
    // report so an ephemeral source port cannot attach a confession to old traffic.
    $sql = "SELECT reportId, remoteUnitId
            FROM hackReport
            WHERE ip = INET_ATON(?)
              AND port = ?
              AND created >= NOW() - INTERVAL 5 SECOND
            ORDER BY created DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $ip, $port);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $reportId = (int)$row['reportId'];
        $oldUnitId = $row['remoteUnitId'] === null ? null : (int)$row['remoteUnitId'];
        if ($oldUnitId !== null && $unitId !== null && $oldUnitId !== $unitId) {
            addWarningRecord('Confession changed remoteUnitId for hack report ' . $reportId
                . ' from ' . $oldUnitId . ' to ' . $unitId);
        }

        if ($unitId !== null) {
            $stmt = $conn->prepare('UPDATE hackReport SET remoteUnitId = ?, ownerConfirmedTime = NOW(), lastSeen = NOW() WHERE reportId = ?');
            $stmt->bind_param('ii', $unitId, $reportId);
        } else {
            $stmt = $conn->prepare('UPDATE hackReport SET ownerConfirmedTime = NOW(), lastSeen = NOW() WHERE reportId = ?');
            $stmt->bind_param('i', $reportId);
        }
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo 'ok matched reportId=' . $reportId . ' ownerConfirmed=1 unitId=' . ($unitId ?? 'unknown');
        exit;
    }

    // Confession won the network race. Preserve it for five seconds so report.php
    // can complete this same row when the victim report arrives instead of creating
    // a duplicate. ownerConfirmedTime records ownership independently of whether
    // the exact owner-generated unit ID is known yet.
    if ($unitId !== null) {
        $sql = "INSERT INTO hackReport (ip, port, remoteUnitId, ownerConfirmedTime, status)
                VALUES (INET_ATON(?), ?, ?, NOW(), 'Awaiting victim report - owner confirmed')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $ip, $port, $unitId);
    } else {
        $sql = "INSERT INTO hackReport (ip, port, ownerConfirmedTime, status)
                VALUES (INET_ATON(?), ?, NOW(), 'Awaiting victim report - owner confirmed, unit unknown')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $ip, $port);
    }
    $stmt->execute();
    $reportId = (int)$conn->insert_id;
    $stmt->close();
    $conn->close();
    echo 'ok created reportId=' . $reportId . ' ownerConfirmed=1 unitId=' . ($unitId ?? 'unknown');
} catch (Throwable $e) {
    error_log('Confession endpoint failed. Sender=' . $sender . ' IP=' . $ip . ':' . $port . ' Error=' . $e->getMessage());
    confessionFail(500, 'database failure');
}
