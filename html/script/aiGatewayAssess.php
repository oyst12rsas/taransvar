<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function replyJson(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function peerIp(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (strncasecmp($ip, '::ffff:', 7) === 0) $ip = substr($ip, 7);
    return $ip;
}

function taraSecConfig(): array
{
    $file = '/etc/tarasec.conf';
    if (!is_readable($file)) return [];
    $cfg = parse_ini_file($file, false, INI_SCANNER_RAW);
    return is_array($cfg) ? $cfg : [];
}

function getSponsoredPolicy(mysqli $conn, string $ip): ?array
{
    $stmt = $conn->prepare(
        "SELECT p.dailyCallLimit,p.taraSecFundedTest+0 funded,p.fundedUntil " .
        "FROM partnerRouter r JOIN aiGatewayPolicy p ON p.gatewayIp=r.ip " .
        "WHERE r.ip=INET_ATON(?) LIMIT 1"
    );
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function callsToday(mysqli $conn, string $ip): int
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) c FROM aiGatewayAssessment " .
        "WHERE gatewayIp=INET_ATON(?) AND fundingMode='tarasec_test' AND created>=CURRENT_DATE()"
    );
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        replyJson(405, ['ok'=>false,'error'=>'post_required']);
    }

    $ip = peerIp();
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        replyJson(403, ['ok'=>false,'error'=>'invalid_gateway_ip']);
    }

    $body = file_get_contents('php://input');
    if ($body === false || strlen($body) > 250000) {
        replyJson(413, ['ok'=>false,'error'=>'request_too_large']);
    }
    $input = json_decode($body, true);
    if (!is_array($input)) replyJson(400, ['ok'=>false,'error'=>'invalid_json']);
    $question = trim((string)($input['question'] ?? ''));
    if ($question === '') replyJson(400, ['ok'=>false,'error'=>'question_required']);

    $conn = getConnection();
    $policy = getSponsoredPolicy($conn, $ip);
    $stillValid = $policy && ($policy['fundedUntil'] === null || strtotime((string)$policy['fundedUntil']) > time());
    if (!$policy || !(int)$policy['funded'] || !$stillValid) {
        $conn->close();
        replyJson(403, ['ok'=>false,'error'=>'gateway_not_sponsored']);
    }

    $limit = max(0, (int)$policy['dailyCallLimit']);
    $used = callsToday($conn, $ip);
    if ($limit > 0 && $used >= $limit) {
        $conn->close();
        replyJson(429, ['ok'=>false,'error'=>'daily_quota_exhausted','used'=>$used,'limit'=>$limit]);
    }

    $cfg = taraSecConfig();
    $flowiseUrl = trim((string)($cfg['TARASEC_FLOWISE_URL'] ?? $cfg['FLOWISE_URL'] ?? ''));
    $flowiseKey = trim((string)($cfg['TARASEC_FLOWISE_API_KEY'] ?? $cfg['FLOWISE_API_KEY'] ?? ''));
    if ($flowiseUrl === '' || $flowiseKey === '') {
        $conn->close();
        replyJson(503, ['ok'=>false,'error'=>'ai_provider_not_configured']);
    }

    $payload = json_encode(['question'=>$question], JSON_UNESCAPED_SLASHES);
    $ch = curl_init($flowiseUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $flowiseKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 90,
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $http < 200 || $http >= 300) {
        error_log("aiGatewayAssess: Flowise failed for $ip HTTP $http $err");
        $conn->close();
        replyJson(502, ['ok'=>false,'error'=>'ai_provider_unavailable']);
    }

    $outer = json_decode($raw, true);
    $text = is_array($outer) ? (string)($outer['text'] ?? '') : '';
    if ($text === '') {
        $conn->close();
        replyJson(502, ['ok'=>false,'error'=>'invalid_ai_response']);
    }
    $text = preg_replace('/^\s*```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```\s*$/', '', $text);
    $assessment = json_decode($text, true);
    if (!is_array($assessment)) {
        $conn->close();
        replyJson(502, ['ok'=>false,'error'=>'invalid_ai_assessment_json']);
    }

    foreach (['unit_assessments','node_assessments','ip_observations','botnet_clusters'] as $requiredArray) {
        if (!isset($assessment[$requiredArray]) || !is_array($assessment[$requiredArray])) {
            $assessment[$requiredArray] = [];
        }
    }

    $assessmentJson = json_encode($assessment, JSON_UNESCAPED_SLASHES);
    $stmt = $conn->prepare(
        "INSERT INTO aiGatewayAssessment (gatewayIp,fundingMode,assessmentJson,reportedBack) " .
        "VALUES (INET_ATON(?),'tarasec_test',?,b'0')"
    );
    $stmt->bind_param('ss', $ip, $assessmentJson);
    $stmt->execute();
    $assessmentId = (int)$stmt->insert_id;
    $stmt->close();

    $gatewayAssessmentId = 'central:' . $assessmentId;
    $stmt = $conn->prepare(
        "UPDATE aiGatewayAssessment SET gatewayAssessmentId=? WHERE aiGatewayAssessmentId=?"
    );
    $stmt->bind_param('si', $gatewayAssessmentId, $assessmentId);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    replyJson(200, [
        'ok'=>true,
        'assessmentId'=>$assessmentId,
        'gatewayAssessmentId'=>$gatewayAssessmentId,
        'fundingMode'=>'tarasec_test',
        'quota'=>['used'=>$used+1,'limit'=>$limit],
        'assessment'=>$assessment
    ]);
} catch (Throwable $e) {
    error_log('aiGatewayAssess.php: '.$e->getMessage());
    replyJson(503, ['ok'=>false,'error'=>'gateway_ai_assessment_unavailable']);
}
