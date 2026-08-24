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
$senderPort = (int)($_SERVER['REMOTE_PORT'] ?? 0);

try {
    if ($wantInfected) {
        /*
         * Use the existing TaraSec incident path. Taralink will resolve the
         * unit and create/reactivate internalInfections, then notify tarakernel.
         * reportHacking() currently prints browser-oriented text, so suppress
         * that output here to keep this endpoint valid JSON.
         */
        ob_start();
        reportHacking('demo', 'User self registered as infected');
        ob_end_clean();

        echo json_encode([
            'ok' => true,
            'requested' => 'infected',
            'message' => 'Infection report submitted to TaraSec'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    /*
     * CLEAN is a local owner action, equivalent to Gatekeeper deactivation.
     * Resolve the current unit from the connection port when possible; fall
     * back to the directly observed sender IP. handled=0 makes the normal
     * configuration path tell tarakernel about the state change.
     */
    $conn = getConnection();
    $unitId = 0;

    if ($senderPort > 0) {
        $stmt = $conn->prepare(
            "SELECT unitId FROM unitPort WHERE port = ? ORDER BY lastSeen DESC LIMIT 1"
        );
        $stmt->bind_param('i', $senderPort);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $unitId = (int)($row['unitId'] ?? 0);
        }
        $stmt->close();
    }

    $changed = 0;
    if ($unitId > 0) {
        $stmt = $conn->prepare(
            "UPDATE internalInfections
             SET active = b'0', handled = b'0', lastSeen = NOW()
             WHERE unitId = ?
             ORDER BY infectionId DESC
             LIMIT 1"
        );
        $stmt->bind_param('i', $unitId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
    }

    if ($changed === 0) {
        $stmt = $conn->prepare(
            "UPDATE internalInfections
             SET active = b'0', handled = b'0', lastSeen = NOW()
             WHERE ip = INET_ATON(?)
             ORDER BY infectionId DESC
             LIMIT 1"
        );
        $stmt->bind_param('s', $sender);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
    }

    $conn->close();

    echo json_encode([
        'ok' => true,
        'requested' => 'clean',
        'changed' => $changed,
        'message' => $changed > 0 ? 'Infection deactivated' : 'No active infection needed changing'
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('appInfectionControl.php failed for ' . $sender . ':' . $senderPort . ': ' . $e->getMessage());
    controlFail(500, 'Unable to change infection state');
}
