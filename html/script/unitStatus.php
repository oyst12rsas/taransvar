<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function statusReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function bearerToken(): string
{
    $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? ''));
    }
    if (!preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $header, $m)) return '';
    return strtolower($m[1]);
}

function latestUnitAi(array $assessment, int $ownerId, int $unitId): ?array
{
    foreach (($assessment['unit_assessments'] ?? []) as $item) {
        if (!is_array($item)) continue;
        if ((int)($item['owner_id'] ?? 0) === $ownerId && (int)($item['unit_id'] ?? 0) === $unitId) return $item;
    }
    return null;
}

$token = bearerToken();
if ($token === '') statusReply(401, ['ok'=>false,'error'=>'unit_token_required']);

try {
    $conn = getConnection();
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT t.unitAppTokenId,t.unitId,u.ownerId,COALESCE(u.hostname,'') hostname,COALESCE(u.description,'') description FROM unitAppToken t JOIN unit u ON u.unitId=t.unitId WHERE t.tokenHash=? AND t.active=b'1' AND (t.expires IS NULL OR t.expires>NOW()) LIMIT 1");
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { $conn->close(); statusReply(401, ['ok'=>false,'error'=>'unit_token_invalid']); }

    $unitId = (int)$row['unitId'];
    $ownerId = $row['ownerId'] === null ? 0 : (int)$row['ownerId'];

    $stmt = $conn->prepare("SELECT status,severity,why,lastSeen,CAST(active AS UNSIGNED) active FROM internalInfections WHERE unitId=? ORDER BY active DESC,lastSeen DESC,infectionId DESC LIMIT 1");
    $stmt->bind_param('i', $unitId);
    $stmt->execute();
    $infection = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) cnt,COALESCE(MAX(severity),0) severity FROM syslogThreat WHERE COALESCE(confirmed_unit_id,unit_id)=? AND COALESCE(lastSeen,created)>=NOW()-INTERVAL 24 HOUR");
    $stmt->bind_param('i', $unitId);
    $stmt->execute();
    $threat = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $ai = null; $aiResponseId = null;
    $result = $conn->query("SELECT aiResponseId,response FROM aiResponse ORDER BY aiResponseId DESC LIMIT 50");
    while ($r = $result->fetch_assoc()) {
        $env = json_decode((string)$r['response'], true);
        if (!is_array($env) || ($env['source'] ?? '') !== 'gateway_local') continue;
        $assessment = $env['assessment'] ?? null;
        if (!is_array($assessment)) continue;
        $candidate = latestUnitAi($assessment, $ownerId, $unitId);
        if ($candidate) { $ai = $candidate; $aiResponseId = (int)$r['aiResponseId']; break; }
    }
    $result->free();

    $infectionActive = $infection && (int)$infection['active'] === 1;
    $infectionSeverity = $infection ? (int)$infection['severity'] : 0;
    $threatSeverity = $threat ? (int)$threat['severity'] : 0;
    $aiSeverity = $ai ? (int)($ai['severity'] ?? 0) : 0;
    $severity = max($infectionSeverity,$threatSeverity,$aiSeverity);

    $subscriptionId = (int)$row['unitAppTokenId'];
    $stmt = $conn->prepare("UPDATE unitAppToken SET lastUsed=NOW() WHERE unitAppTokenId=?");
    $stmt->bind_param('i', $subscriptionId);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    statusReply(200, [
        'ok'=>true,'scope'=>'single_unit_read_only','subscriptionId'=>$subscriptionId,
        'unit'=>['unitId'=>$unitId,'ownerId'=>$ownerId ?: null,'hostname'=>(string)$row['hostname'],'description'=>(string)$row['description']],
        'threat'=>[
            'warning'=>$infectionActive || $severity>1,'confirmedLocalInfection'=>$infectionActive,'severity'=>$severity,
            'infectionStatus'=>$infection ? $infection['status'] : null,'why'=>$infection ? $infection['why'] : null,
            'recentThreatRecords24h'=>$threat ? (int)$threat['cnt'] : 0,'maxThreatSeverity24h'=>$threatSeverity,
            'aiSeverity'=>$aiSeverity,'aiSummary'=>$ai ? (string)($ai['summary'] ?? '') : '',
            'aiCategory'=>$ai ? (string)($ai['category'] ?? '') : '','aiResponseId'=>$aiResponseId
        ],
        'privacy'=>['managerAccess'=>false,'singleUnitOnly'=>true,'personalIdentityReturned'=>false],
        'server_time'=>gmdate('c')
    ]);
} catch (Throwable $e) {
    error_log('unitStatus.php: '.$e->getMessage());
    statusReply(503, ['ok'=>false,'error'=>'unit_status_unavailable']);
}
