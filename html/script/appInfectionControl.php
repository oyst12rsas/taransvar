<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';
include '../taraLib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function controlFail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    controlFail(405, 'POST required');
}

$infectedParam = (string)($_POST['infected'] ?? '');
if ($infectedParam !== '0' && $infectedParam !== '1') {
    controlFail(400, 'infected must be 0 or 1');
}

$wantInfected = $infectedParam === '1';
$sender = getSenderIp();

if (filter_var($sender, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
    controlFail(400, 'Unable to identify calling IPv4 client');
}

try {
    $conn = getConnection();

    // The app toggle describes the calling device itself. Do not create a
    // hackReport and do not infer another unit from the TCP source port.
    // The local TaraSec gateway sees the phone's LAN address and marks that
    // exact /32 in internalInfections so tarakernel can tag its traffic.
    $stmt = $conn->prepare(
        "SELECT infectionId
           FROM internalInfections
          WHERE ip = INET_ATON(?)
          ORDER BY infectionId DESC
          LIMIT 1"
    );
    $stmt->bind_param('s', $sender);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $infectionId = $row ? (int)$row['infectionId'] : 0;
    $severity = 3;
    $why = 'TaraSec app: device self-declared infected';

    if ($wantInfected) {
        if ($infectionId > 0) {
            $stmt = $conn->prepare(
                "UPDATE internalInfections
                    SET active = b'1',
                        handled = b'0',
                        severity = ?,
                        status = 'unknown',
                        nettmask = 4294967295,
                        why = ?,
                        lastSeen = NOW()
                  WHERE infectionId = ?"
            );
            $stmt->bind_param('isi', $severity, $why, $infectionId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO internalInfections
                    (ip, nettmask, status, handled, active, lastSeen, severity, why)
                 VALUES
                    (INET_ATON(?), 4294967295, 'unknown', b'0', b'1', NOW(), ?, ?)"
            );
            $stmt->bind_param('sis', $sender, $severity, $why);
            $stmt->execute();
            $infectionId = (int)$conn->insert_id;
            $stmt->close();
        }

        $conn->close();
        echo json_encode([
            'ok' => true,
            'requested' => 'infected',
            'client_ip' => $sender,
            'infectionId' => $infectionId,
            'severity' => $severity,
            'message' => 'This device is marked infected on the local TaraSec gateway'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $changed = 0;
    if ($infectionId > 0) {
        $stmt = $conn->prepare(
            "UPDATE internalInfections
                SET active = b'0', handled = b'0', lastSeen = NOW()
              WHERE infectionId = ?"
        );
        $stmt->bind_param('i', $infectionId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
    }

    $conn->close();
    echo json_encode([
        'ok' => true,
        'requested' => 'clean',
        'client_ip' => $sender,
        'infectionId' => $infectionId,
        'changed' => $changed,
        'message' => $infectionId > 0 ? 'This device is marked clean on the local TaraSec gateway' : 'This device had no infection record'
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('appInfectionControl.php failed for ' . $sender . ': ' . $e->getMessage());
    controlFail(500, 'Unable to change this device infection state');
}
