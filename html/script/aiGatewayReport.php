<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function reportReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function reportPeerIp(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (strncasecmp($ip, '::ffff:', 7) === 0) $ip = substr($ip, 7);
    return $ip;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') reportReply(405, ['ok'=>false,'error'=>'post_required']);

    $ip = reportPeerIp();
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        reportReply(403, ['ok'=>false,'error'=>'invalid_gateway_ip']);
    }

    $body = file_get_contents('php://input');
    if ($body === false || strlen($body) > 500000) reportReply(413, ['ok'=>false,'error'=>'request_too_large']);
    $data = json_decode($body, true);
    if (!is_array($data)) reportReply(400, ['ok'=>false,'error'=>'invalid_json']);

    $assessment = $data['assessment'] ?? null;
    if (!is_array($assessment)) reportReply(400, ['ok'=>false,'error'=>'assessment_required']);

    $fundingMode = (string)($data['fundingMode'] ?? 'owner_funded');
    if (!in_array($fundingMode, ['tarasec_test','owner_funded'], true)) {
        reportReply(400, ['ok'=>false,'error'=>'invalid_funding_mode']);
    }

    $gatewayAssessmentId = trim((string)($data['gatewayAssessmentId'] ?? ''));
    if ($gatewayAssessmentId !== '' && strlen($gatewayAssessmentId) > 128) {
        reportReply(400, ['ok'=>false,'error'=>'assessment_id_too_long']);
    }

    $evidenceSummary = $data['evidenceSummary'] ?? null;
    $assessmentJson = json_encode($assessment, JSON_UNESCAPED_SLASHES);
    $evidenceJson = $evidenceSummary === null ? null : json_encode($evidenceSummary, JSON_UNESCAPED_SLASHES);

    $conn = getConnection();
    $stmt = $conn->prepare('SELECT 1 FROM partnerRouter WHERE ip=INET_ATON(?) LIMIT 1');
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $registered = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$registered) {
        $conn->close();
        reportReply(403, ['ok'=>false,'error'=>'unregistered_gateway']);
    }

    if ($gatewayAssessmentId === '') {
        $stmt = $conn->prepare(
            "INSERT INTO aiGatewayAssessment " .
            "(gatewayIp,fundingMode,gatewayAssessmentId,assessmentJson,evidenceSummaryJson,reportedBack) " .
            "VALUES (INET_ATON(?),?,NULL,?,?,b'1')"
        );
        $stmt->bind_param('ssss', $ip, $fundingMode, $assessmentJson, $evidenceJson);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO aiGatewayAssessment " .
            "(gatewayIp,fundingMode,gatewayAssessmentId,assessmentJson,evidenceSummaryJson,reportedBack) " .
            "VALUES (INET_ATON(?),?,?,?,?,b'1') " .
            "ON DUPLICATE KEY UPDATE fundingMode=VALUES(fundingMode),assessmentJson=VALUES(assessmentJson)," .
            "evidenceSummaryJson=VALUES(evidenceSummaryJson),reportedBack=b'1'"
        );
        $stmt->bind_param('sssss', $ip, $fundingMode, $gatewayAssessmentId, $assessmentJson, $evidenceJson);
    }
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    $conn->close();

    reportReply(202, ['ok'=>true,'accepted'=>true,'id'=>$id]);
} catch (Throwable $e) {
    error_log('aiGatewayReport.php: '.$e->getMessage());
    reportReply(503, ['ok'=>false,'error'=>'gateway_ai_report_unavailable']);
}
