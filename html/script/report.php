<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';
include '../taraLib.php';

header('Content-Type: text/plain; charset=utf-8');

function reportFail(int $status, string $message): void
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
    reportFail(403, 'invalid sender');
}

if (!isset($_GET['ip'], $_GET['port'])) {
    reportFail(400, 'missing ip or port');
}

$ip = trim((string)$_GET['ip']);
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    reportFail(400, 'invalid ip');
}

$port = filter_var($_GET['port'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => 65535]
]);
if ($port === false) {
    reportFail(400, 'invalid port');
}

$why = isset($_GET['wt']) ? trim((string)$_GET['wt']) : 'hack';
if ($why === '') {
    $why = 'hack';
}
$why = substr($why, 0, 255);

$category = isset($_GET['code']) ? trim((string)$_GET['code']) : 'other';
$allowedCategories = [
    'login_fail', 'tagged_traffic', 'from_dbserver', 'from_partner',
    'ssh_fail', 'ssh_when_blocked', 'iptables', 'attack_severity_1',
    'attack_severity_3', 'attack_severity_7', 'other', 'demo'
];
if (!in_array($category, $allowedCategories, true)) {
    $category = 'other';
}

$ourId = isset($_GET['ourid']) ? (int)$_GET['ourid'] : 0;
$fromPort = isset($_SERVER['REMOTE_PORT']) ? (int)$_SERVER['REMOTE_PORT'] : 0;

try {
    $conn = getConnection();

    // A hack report is a control-plane message. Accept it only from a known
    // partner router or one of this node's configured global DB servers.
    $sql = "SELECT 1 FROM partnerRouter WHERE ip = INET_ATON(?) LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $sender);
    $stmt->execute();
    $trustedSender = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();

    if (!$trustedSender) {
        $sql = "SELECT 1 FROM setup
                WHERE globalDb1ip = INET_ATON(?)
                   OR globalDb2ip = INET_ATON(?)
                   OR globalDb3ip = INET_ATON(?)
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sss', $sender, $sender, $sender);
        $stmt->execute();
        $trustedSender = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
    }

    if (!$trustedSender) {
        $conn->close();
        reportFail(403, 'untrusted sender');
    }

    // First resolve the race where the owner confession reached the global DB
    // before the victim's report. A confession-created row is deliberately
    // incomplete (sentByIp is NULL) and is only eligible for five seconds.
    // ownerConfirmedTime, rather than remoteUnitId, is the authoritative marker:
    // the owner may have confirmed responsibility before resolving the exact unit.
    $sql = "SELECT reportId
            FROM hackReport
            WHERE ip = INET_ATON(?)
              AND port = ?
              AND ownerConfirmedTime IS NOT NULL
              AND sentByIp IS NULL
              AND created >= NOW() - INTERVAL 5 SECOND
            ORDER BY created DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $ip, $port);
    $stmt->execute();
    $confessedRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($confessedRow) {
        $reportId = (int)$confessedRow['reportId'];
        $sql = "UPDATE hackReport
                SET partnerIp = INET_ATON(?), partnerPort = ?, hrCategory = ?, why = ?,
                    sentByIp = INET_ATON(?), ipOwnerId = ?, lastSeen = NOW(), count = count + 1
                WHERE reportId = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sisssii', $sender, $fromPort, $category, $why, $sender, $ourId, $reportId);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        error_log('Hack report completed confession-first reportId=' . $reportId . ' sender=' . $sender);
        echo 'ok';
        exit;
    }

    // Normal duplicate suppression for repeated delivery of the same report.
    $sql = "SELECT reportId,
                   TIMESTAMPDIFF(SECOND, COALESCE(lastSeen, created), NOW()) AS seconds_since
            FROM hackReport
            WHERE ip = INET_ATON(?)
              AND port = ?
              AND hrCategory = ?
              AND why = ?
            ORDER BY COALESCE(lastSeen, created) DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('siss', $ip, $port, $category, $why);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && (int)$row['seconds_since'] < 30) {
        $reportId = (int)$row['reportId'];
        $stmt = $conn->prepare('UPDATE hackReport SET count = count + 1, lastSeen = NOW() WHERE reportId = ?');
        $stmt->bind_param('i', $reportId);
        $stmt->execute();
        $stmt->close();
    } else {
        $sql = "INSERT INTO hackReport
                    (ip, port, partnerIp, partnerPort, hrCategory, why, sentByIp, ipOwnerId)
                VALUES
                    (INET_ATON(?), ?, INET_ATON(?), ?, ?, ?, INET_ATON(?), ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sisisssi', $ip, $port, $sender, $fromPort, $category, $why, $sender, $ourId);
        $stmt->execute();
        $reportId = (int)$conn->insert_id;
        $stmt->close();
    }

    $conn->close();
    error_log('Hack report accepted reportId=' . $reportId . ' sender=' . $sender . ' source=' . $ip . ':' . $port);
    echo 'ok';
} catch (Throwable $e) {
    error_log('Hack report endpoint failed. Sender=' . $sender . ' IP=' . $ip . ':' . $port . ' Error=' . $e->getMessage());
    reportFail(500, 'database failure');
}
