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
    // Start with the canonical assessment shared with the web UI, then
    // reconcile evidence that arrives during this request's short wait.
    $data = getTagData();

    /*
     * Evidence is matched to this request's exact TCP source port. Wait up to
     * 1.5 seconds for tarakernel's one-second queue flush. A single 300 ms
     * retry raced the receiver and could return the preceding state.
     */
    $deadline = microtime(true) + 1.5;
    while (true) {
        $trafficAge = (int)($data['trafficSecondsSince'] ?? -1);
        // A mobile client can quickly reuse a TCP source port. Only an
        // observation updated in the current second proves that this request,
        // rather than a preceding red/green request, has reached the database.
        $conflictingEvidence = $trafficAge === 0
            && (bool)($data['hackReportExactPort'] ?? false)
            && (int)($data['hackReportSecondsSince'] ?? -1) >= 0
            && (int)($data['hackReportSecondsSince'] ?? -1) <= 2
            && (int)($data['hackReportSeverity'] ?? 0) > (int)($data['trafficSeverity'] ?? 0);
        // The first packet can be recorded at severity 1 before later packets
        // update this same flow to severity 3. Give the traffic row time to
        // catch up with a current, exact-port threat report.
        if ($trafficAge === 0 && !$conflictingEvidence) {
            break;
        }
        if (microtime(true) >= $deadline) {
            break;
        }
        usleep(100000);
        $data = getTagData();
    }

    // If the traffic writer still lags, a threat report for this exact TCP
    // connection in the last two seconds is stronger evidence than its first
    // severity-1 packet. Port-zero and older reports cannot override a tag.
    $freshExactPortReport = (bool)($data['hackReportExactPort'] ?? false)
        && (int)($data['hackReportSecondsSince'] ?? -1) >= 0
        && (int)($data['hackReportSecondsSince'] ?? -1) <= 2
        && (int)($data['trafficSecondsSince'] ?? -1) >= 0
        && (int)($data['trafficSecondsSince'] ?? -1) <= 2
        && (int)($data['hackReportSeverity'] ?? 0) > (int)($data['trafficSeverity'] ?? 0);
    $severity = $freshExactPortReport
        ? (int)$data['hackReportSeverity']
        : (int)($data['severity'] ?? 0);

    echo json_encode([
        'ok' => true,
        'infected' => $severity > 1,
        'severity' => $severity,
        'source' => $freshExactPortReport ? 'hackReport' : (
            ((int)($data['trafficSecondsSince'] ?? -1) >= 0 && (int)($data['trafficSecondsSince'] ?? -1) < 45)
                ? 'traffic'
                : (((int)($data['hackReportSecondsSince'] ?? -1) >= 0) ? 'hackReport' : 'internalInfections')
        ),
        'client_ip' => (string)($data['senderIp'] ?? getSenderIp()),
        'client_port' => (int)($data['senderPort'] ?? ($_SERVER['REMOTE_PORT'] ?? 0)),
        'trafficSeverity' => (int)($data['trafficSeverity'] ?? 0),
        'trafficSecondsSince' => (int)($data['trafficSecondsSince'] ?? -1),
        'demo' => ((int)($data['trafficIsDemo'] ?? 0)) === 1,
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
