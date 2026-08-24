<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';
include '../taraLib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function appInfectionFail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // This endpoint deliberately contains no independent infection logic.
    // It is the JSON view of the same canonical assessment used by the web UI.
    $data = getTagData();

    // On a receiving node the packet carrying this very HTTP request may be
    // logged asynchronously.  Keep the same TCP source port and retry briefly
    // so getTagData() can see the TaraSec tag from this request itself.
    $trafficAge = (int)($data['trafficSecondsSince'] ?? -1);
    if ($trafficAge < 0 || $trafficAge >= 45) {
        usleep(300000);
        $data = getTagData();
    }

    echo json_encode([
        'ok' => true,
        'infected' => ((int)($data['severity'] ?? 0)) > 1,
        'severity' => (int)($data['severity'] ?? 0),
        'source' => (
            ((int)($data['trafficSecondsSince'] ?? -1) >= 0 && (int)($data['trafficSecondsSince'] ?? -1) < 45)
                ? 'traffic'
                : (((int)($data['hackReportSecondsSince'] ?? -1) >= 0) ? 'hackReport' : 'internalInfections')
        ),
        'client_ip' => (string)($data['senderIp'] ?? getSenderIp()),
        'client_port' => (int)($data['senderPort'] ?? ($_SERVER['REMOTE_PORT'] ?? 0)),
        'trafficSeverity' => (int)($data['trafficSeverity'] ?? 0),
        'trafficSecondsSince' => (int)($data['trafficSecondsSince'] ?? -1),
        'hackReportSeverity' => (int)($data['hackReportSeverity'] ?? 0),
        'hackReportSecondsSince' => (int)($data['hackReportSecondsSince'] ?? -1),
        'infectionSeverity' => (int)($data['infectionSeverity'] ?? -1),
        'infectionDisabled' => (int)($data['infectionDisabled'] ?? 0),
        'server_time' => gmdate('c')
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('appInfection.php failed: ' . $e->getMessage());
    appInfectionFail(500, 'Status lookup failed');
}
