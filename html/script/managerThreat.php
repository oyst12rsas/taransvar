<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function threatReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function startThreatManagerSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

function latestGatewayAiAssessment(mysqli $conn): ?array
{
    // aiResponse also contains central/back-office assessments. Gateway-local
    // assessments are explicitly wrapped with source=gateway_local, so find the
    // newest such envelope without depending on a schema change.
    $result = $conn->query("SELECT aiResponseId,created,response FROM aiResponse ORDER BY aiResponseId DESC LIMIT 50");
    while ($row = $result->fetch_assoc()) {
        $envelope = json_decode((string)$row['response'], true);
        if (!is_array($envelope) || ($envelope['source'] ?? '') !== 'gateway_local') continue;
        $assessment = $envelope['assessment'] ?? null;
        if (!is_array($assessment)) continue;
        $result->free();
        return [
            'aiResponseId' => (int)$row['aiResponseId'],
            'created' => (string)$row['created'],
            'fundingMode' => (string)($envelope['fundingMode'] ?? ''),
            'assessment' => $assessment,
        ];
    }
    $result->free();
    return null;
}

function maxAiSeverity(array $assessment): int
{
    $severity = (int)($assessment['event_severity'] ?? $assessment['severity'] ?? 0);
    foreach (($assessment['unit_assessments'] ?? []) as $unit) {
        if (is_array($unit)) $severity = max($severity, (int)($unit['severity'] ?? 0));
    }
    return $severity;
}

startThreatManagerSession();
if (empty($_SESSION['tarasec_manager_authenticated'])) {
    threatReply(401, ['ok' => false, 'error' => 'manager_session_required']);
}

try {
    $conn = getConnection();

    $requestId = (int)($_SESSION['tarasec_manager_request_id'] ?? 0);
    if ($requestId <= 0) {
        $conn->close();
        threatReply(401, ['ok' => false, 'error' => 'manager_session_invalid']);
    }

    $stmt = $conn->prepare("SELECT 1 FROM managerRequest WHERE managerRequestId=? AND active=b'1' AND rejectedTime IS NULL AND (expires IS NULL OR expires>NOW()) LIMIT 1");
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $activeManager = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$activeManager) {
        $conn->close();
        threatReply(401, ['ok' => false, 'error' => 'manager_access_no_longer_active']);
    }

    $activeInfections = 0;
    $infectionSeverity = 0;
    $result = $conn->query("SELECT COUNT(*) AS cnt, COALESCE(MAX(severity),0) AS severity FROM internalInfections WHERE active=b'1'");
    if ($row = $result->fetch_assoc()) {
        $activeInfections = (int)$row['cnt'];
        $infectionSeverity = (int)$row['severity'];
    }
    $result->free();

    $recentReports = 0;
    $reportSeverity = 0;
    $result = $conn->query("SELECT COUNT(*) AS cnt, COALESCE(MAX(severity),0) AS severity FROM hackReport WHERE lastSeen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    if ($row = $result->fetch_assoc()) {
        $recentReports = (int)$row['cnt'];
        $reportSeverity = (int)$row['severity'];
    }
    $result->free();

    $nickname = '';
    $result = $conn->query("SELECT COALESCE(nickname,'') AS nickname FROM setup LIMIT 1");
    if ($row = $result->fetch_assoc()) $nickname = (string)$row['nickname'];
    $result->free();

    // AI is supporting evidence, not confirmed infection state.  It nevertheless
    // belongs in Threat Watch: if this installation's latest AI assessment says a
    // managed local unit is behaving suspiciously, the owner should see an alarm.
    $aiEnvelope = latestGatewayAiAssessment($conn);
    $aiSeverity = 0;
    $aiCategory = '';
    $aiSummary = '';
    $aiAssessmentTime = null;
    $aiResponseId = null;
    if ($aiEnvelope) {
        $assessment = $aiEnvelope['assessment'];
        $aiSeverity = maxAiSeverity($assessment);
        $aiCategory = (string)($assessment['category'] ?? '');
        $aiSummary = trim((string)($assessment['summary'] ?? ''));
        $aiAssessmentTime = $aiEnvelope['created'];
        $aiResponseId = $aiEnvelope['aiResponseId'];
    }

    $severity = max($infectionSeverity, $reportSeverity, $aiSeverity);
    $confirmedInfection = $activeInfections > 0;
    $warning = $confirmedInfection || $reportSeverity > 1 || $aiSeverity > 1;

    if ($aiSeverity >= max($infectionSeverity, $reportSeverity) && $aiSeverity > 1) {
        $summary = $aiSummary !== ''
            ? 'AI warning for this installation: ' . $aiSummary
            : sprintf('AI warning for a managed unit on this installation (severity %d)', $aiSeverity);
    } elseif ($warning) {
        $summary = sprintf('%d active infection record(s), %d recent threat report(s)', $activeInfections, $recentReports);
    } else {
        $summary = 'No active local infection records, recent threat reports, or AI warning';
    }

    $conn->close();
    threatReply(200, [
        'ok' => true,
        'nickname' => $nickname,
        // Keep infected reserved for locally confirmed infection state. The App
        // already raises Threat Watch when severity > 1, so AI can warn without
        // being silently promoted to a confirmed infection.
        'infected' => $confirmedInfection,
        'warning' => $warning,
        'severity' => $severity,
        'activeInfections' => $activeInfections,
        'recentReports' => $recentReports,
        'infectionSeverity' => $infectionSeverity,
        'reportSeverity' => $reportSeverity,
        'aiSeverity' => $aiSeverity,
        'aiCategory' => $aiCategory,
        'aiSummary' => $aiSummary,
        'aiAssessmentTime' => $aiAssessmentTime,
        'aiResponseId' => $aiResponseId,
        'summary' => $summary,
        'server_time' => gmdate('c')
    ]);
} catch (Throwable $e) {
    error_log('managerThreat.php: ' . $e->getMessage());
    threatReply(503, ['ok' => false, 'error' => 'manager_threat_unavailable']);
}
