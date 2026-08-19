<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function aiReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function tableExists(mysqli $conn, string $name): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function decodeJsonField(?string $value)
{
    if ($value === null || $value === '') return null;
    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['tarasec_manager_authenticated'])) {
        aiReply(401, ['ok'=>false,'error'=>'manager_session_required']);
    }

    $requestId = (int)($_SESSION['tarasec_manager_request_id'] ?? 0);
    if ($requestId <= 0) aiReply(401, ['ok'=>false,'error'=>'manager_session_invalid']);

    $conn = getConnection();
    $stmt = $conn->prepare(
        "SELECT managerRequestId,email FROM managerRequest " .
        "WHERE managerRequestId=? AND active=b'1' AND rejectedTime IS NULL " .
        "AND (expires IS NULL OR expires>NOW()) LIMIT 1"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $manager = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$manager) {
        $conn->close();
        aiReply(403, ['ok'=>false,'error'=>'manager_access_no_longer_active']);
    }

    $gatewayAssessment = null;
    $gatewayAssessmentTime = null;
    $result = $conn->query('SELECT aiAssessment,aiAssessmentTime FROM setup LIMIT 1');
    if ($result && ($row = $result->fetch_assoc())) {
        $gatewayAssessmentTime = $row['aiAssessmentTime'];
        $gatewayAssessment = decodeJsonField($row['aiAssessment']);
    }
    if ($result) $result->free();

    // Keep the central-candidate view available on DB-server installations for
    // Gatekeeper/back-office compatibility, but the App-facing primary result is
    // the gateway-local assessment above.
    $units = [];
    if (tableExists($conn, 'aiUnitAssessment')) {
        $sql = "SELECT a.aiUnitAssessmentId,a.aiResponseId,a.created,a.ownerId,a.unitId,a.confidence,a.severity,a.category,a.summary,a.evidenceJson " .
               "FROM aiUnitAssessment a JOIN (SELECT ownerId,unitId,MAX(aiResponseId) latestResponse FROM aiUnitAssessment GROUP BY ownerId,unitId) latest " .
               "ON latest.ownerId=a.ownerId AND latest.unitId=a.unitId AND latest.latestResponse=a.aiResponseId " .
               "ORDER BY COALESCE(a.confidence,0) DESC,a.severity DESC,a.created DESC LIMIT 100";
        $result = $conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            $units[] = [
                'id'=>(int)$row['aiUnitAssessmentId'],
                'aiResponseId'=>(int)$row['aiResponseId'],
                'created'=>$row['created'],
                'ownerId'=>(int)$row['ownerId'],
                'unitId'=>(int)$row['unitId'],
                'confidence'=>$row['confidence']===null?null:(float)$row['confidence'],
                'severity'=>$row['severity']===null?null:(int)$row['severity'],
                'category'=>$row['category'],
                'summary'=>$row['summary'],
                'evidence'=>decodeJsonField($row['evidenceJson']),
                'state'=>'ai_candidate'
            ];
        }
        $result->free();
    }

    $botnets = [];
    if (tableExists($conn, 'aiBotnetCandidate')) {
        $sql = "SELECT b.aiBotnetCandidateId,b.aiResponseId,b.created,b.candidateKey,b.confidence,b.summary,b.membersJson,b.evidenceJson " .
               "FROM aiBotnetCandidate b JOIN (SELECT candidateKey,MAX(aiResponseId) latestResponse FROM aiBotnetCandidate GROUP BY candidateKey) latest " .
               "ON latest.candidateKey=b.candidateKey AND latest.latestResponse=b.aiResponseId " .
               "ORDER BY COALESCE(b.confidence,0) DESC,b.created DESC LIMIT 100";
        $result = $conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            $botnets[] = [
                'id'=>(int)$row['aiBotnetCandidateId'],
                'aiResponseId'=>(int)$row['aiResponseId'],
                'created'=>$row['created'],
                'candidateKey'=>$row['candidateKey'],
                'confidence'=>$row['confidence']===null?null:(float)$row['confidence'],
                'summary'=>$row['summary'],
                'members'=>decodeJsonField($row['membersJson']),
                'evidence'=>decodeJsonField($row['evidenceJson']),
                'state'=>'ai_candidate'
            ];
        }
        $result->free();
    }

    $conn->close();
    aiReply(200, [
        'ok'=>true,
        'available'=>$gatewayAssessment !== null || count($units)>0 || count($botnets)>0,
        'manager'=>['email'=>(string)$manager['email'],'requestId'=>(int)$manager['managerRequestId']],
        'gatewayAssessment'=>$gatewayAssessment,
        'gatewayAssessmentTime'=>$gatewayAssessmentTime,
        'units'=>$units,
        'botnets'=>$botnets,
        'warning'=>'AI assessments are supporting evidence, not confirmed infection state.'
    ]);
} catch (Throwable $e) {
    error_log('managerAi.php: '.$e->getMessage());
    aiReply(503, ['ok'=>false,'error'=>'manager_ai_unavailable']);
}
