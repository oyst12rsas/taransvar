<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';
include '../taraLib.php';
require_once __DIR__ . '/webPokeLegacyGuard.php';
webPokeLegacyAccess();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// The DB-hosted HTTP demo must mark the browser device on its observed gateway.
// This endpoint already accepts unauthenticated native-app requests; CORS lets
// the browser demo make the same direct request.
header('Access-Control-Allow-Origin: *');

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
$isDemo = ((string)($_POST['demo'] ?? '0') === '1');
require_once __DIR__ . '/../deploymentMode.php';
if ($isDemo) tarasecRequireDemo();
$demoSeverity = null;
if (isset($_POST['severity'])) {
    if (!$isDemo) {
        controlFail(400, 'severity override is demo-only');
    }
    $demoSeverity = filter_var($_POST['severity'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => 10]
    ]);
    if ($demoSeverity === false) {
        controlFail(400, 'severity must be between 0 and 10');
    }
    if ((int)$demoSeverity === 0) {
        $wantInfected = false;
    } else {
        $wantInfected = true;
    }
}

$sender = getSenderIp();

if (filter_var($sender, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
    controlFail(400, 'Unable to identify calling IPv4 client');
}

try {
    $conn = getConnection();
    $selfDeclared = 'TaraSec app: device self-declared infected';

    // The app toggle describes the calling device itself. Do not create a
    // hackReport and do not infer another unit from the TCP source port.
    // The local TaraSec gateway sees the phone's LAN address and marks that
    // exact /32 in internalInfections so tarakernel can tag its traffic.
    // Demo controls must never overwrite a genuine infection row merely
    // because it is the newest record for this address.
    $lookupSql =
        "SELECT infectionId
           FROM internalInfections
          WHERE ip = INET_ATON(?)" .
        ($isDemo ? " AND why LIKE 'DEMO:%' AND COALESCE(botnetId, 0) = 0" : " AND why = ? AND COALESCE(botnetId, 0) = 0") .
        " ORDER BY infectionId DESC
          LIMIT 1";
    $stmt = $conn->prepare($lookupSql);
    if ($isDemo) {
        $stmt->bind_param('s', $sender);
    } else {
        $stmt->bind_param('ss', $sender, $selfDeclared);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $infectionId = $row ? (int)$row['infectionId'] : 0;
    $severity = $demoSeverity !== null ? (int)$demoSeverity : 3;
    // This text is carried in TaraSec's elaborated threat-info exchange. The
    // receiver uses the stable DEMO prefix to classify the resulting report.
    $why = $isDemo
        ? 'DEMO:TaraSec app: device self-declared infected'
        : $selfDeclared;

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
                  WHERE infectionId = ?" . ($isDemo
                      ? " AND why LIKE 'DEMO:%' AND COALESCE(botnetId, 0) = 0"
                      : " AND why = ? AND COALESCE(botnetId, 0) = 0")
            );
            if ($isDemo) {
                $stmt->bind_param('isi', $severity, $why, $infectionId);
            } else {
                $stmt->bind_param('isis', $severity, $why, $infectionId, $selfDeclared);
            }
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
            'demo' => $isDemo,
            'message' => 'This device is marked infected on the local TaraSec gateway'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $changed = 0;
    if ($isDemo) {
        // Reset every demo-only row for this device on the current gateway.
        // Genuine security findings are deliberately left active.
        $stmt = $conn->prepare(
            "UPDATE internalInfections
                SET active = b'0', handled = b'0', lastSeen = NOW()
              WHERE ip = INET_ATON(?)
                AND active = b'1'
                AND why LIKE 'DEMO:%'
                AND COALESCE(botnetId, 0) = 0"
        );
        $stmt->bind_param('s', $sender);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
    } else {
        // The client may withdraw its own initial declaration. Findings
        // created by the gateway or assigned to a botnet must go through the
        // router manager or the explicit global-DB clear path in Taralink.
        $stmt = $conn->prepare(
            "UPDATE internalInfections
                SET active = b'0', handled = b'0', lastSeen = NOW()
              WHERE ip = INET_ATON(?)
                AND active = b'1'
                AND why = ?
                AND COALESCE(botnetId, 0) = 0"
        );
        $stmt->bind_param('ss', $sender, $selfDeclared);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        $stmt = $conn->prepare(
            "SELECT 1 FROM internalInfections I
              WHERE I.active = b'1'
                AND (I.why IS NULL OR I.why NOT LIKE 'DEMO:%' OR COALESCE(I.botnetId, 0) <> 0)
                AND (I.ip = INET_ATON(?) OR EXISTS (
                    SELECT 1 FROM unit U WHERE U.unitId = I.unitId AND U.ipAddress = INET_ATON(?)
                ))
              LIMIT 1"
        );
        $stmt->bind_param('ss', $sender, $sender);
        $stmt->execute();
        $restricted = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        if ($restricted) {
            controlFail(409, 'Device has an active infection finding. Contact your router manager.');
        }
    }

    $conn->close();
    echo json_encode([
        'ok' => true,
        'requested' => 'clean',
        'client_ip' => $sender,
        'infectionId' => $infectionId,
        'severity' => 0,
        'changed' => $changed,
        'demo' => $isDemo,
        'message' => $isDemo
            ? ($changed > 0 ? 'Demo state reset on the current TaraSec gateway' : 'This device had no active demo infection state on the current gateway')
            : ($changed > 0 ? 'Self-declared infection cleared on the local TaraSec gateway' : 'This device had no active self-declared infection')
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('appInfectionControl.php failed for ' . $sender . ': ' . $e->getMessage());
    controlFail(500, 'Unable to change this device infection state');
}
