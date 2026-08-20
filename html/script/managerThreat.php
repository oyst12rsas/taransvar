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

startThreatManagerSession();
if (empty($_SESSION['tarasec_manager_authenticated'])) {
    threatReply(401, ['ok' => false, 'error' => 'manager_session_required']);
}

try {
    $conn = getConnection();

    // Confirm that the credential/session still belongs to an active manager.
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
    if ($row = $result->fetch_assoc()) {
        $nickname = (string)$row['nickname'];
    }
    $result->free();

    $severity = max($infectionSeverity, $reportSeverity);
    $infected = $activeInfections > 0 || $severity > 1;
    $summary = $infected
        ? sprintf('%d active infection record(s), %d recent threat report(s)', $activeInfections, $recentReports)
        : 'No active local infection records or recent threat reports';

    $conn->close();
    threatReply(200, [
        'ok' => true,
        'nickname' => $nickname,
        'infected' => $infected,
        'severity' => $severity,
        'activeInfections' => $activeInfections,
        'recentReports' => $recentReports,
        'infectionSeverity' => $infectionSeverity,
        'reportSeverity' => $reportSeverity,
        'summary' => $summary,
        'server_time' => gmdate('c')
    ]);
} catch (Throwable $e) {
    error_log('managerThreat.php: ' . $e->getMessage());
    threatReply(503, ['ok' => false, 'error' => 'manager_threat_unavailable']);
}
