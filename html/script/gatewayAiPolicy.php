<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function replyAiPolicy(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function peerIpv4(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (strncasecmp($ip, '::ffff:', 7) === 0) $ip = substr($ip, 7);
    return $ip;
}

try {
    $ip = peerIpv4();
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        replyAiPolicy(403, ['ok' => false, 'error' => 'untrusted_sender']);
    }

    $conn = getConnection();

    // This endpoint is intended for the global DB server only.
    $r = $conn->query("SELECT isGlobalDbServer FROM setup LIMIT 1");
    $setup = $r ? $r->fetch_assoc() : null;
    if (!$setup || (int)$setup['isGlobalDbServer'] !== 1) {
        $conn->close();
        replyAiPolicy(403, ['ok' => false, 'error' => 'not_global_db_server']);
    }

    // Source-IP identity is accepted only for a registered TaraSec/network router.
    // The endpoint must be reached over the TaraSec/private network; forwarding
    // headers are deliberately ignored.
    $stmt = $conn->prepare('SELECT 1 FROM partnerRouter WHERE ip=INET_ATON(?) LIMIT 1');
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $registered = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$registered) {
        $conn->close();
        replyAiPolicy(403, ['ok' => false, 'error' => 'unregistered_gateway']);
    }

    $table = $conn->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='aiGatewayPolicy' LIMIT 1");
    $hasPolicyTable = $table && $table->fetch_row();

    $funded = false;
    $limit = 0;
    $until = null;
    $comment = null;
    if ($hasPolicyTable) {
        $stmt = $conn->prepare(
            "SELECT taraSecFundedTest+0 funded,dailyCallLimit,fundedUntil,comment " .
            "FROM aiGatewayPolicy WHERE gatewayIp=INET_ATON(?) LIMIT 1"
        );
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $until = $row['fundedUntil'];
            $stillValid = ($until === null || strtotime($until) > time());
            $funded = ((int)$row['funded'] === 1 && $stillValid);
            $limit = $funded ? (int)$row['dailyCallLimit'] : 0;
            $comment = $row['comment'];
        }
    }

    $conn->close();
    replyAiPolicy(200, [
        'ok' => true,
        'gatewayIp' => $ip,
        'mode' => $funded ? 'tarasec_test' : 'owner_funded',
        'taraSecFundedTest' => $funded,
        'dailyCallLimit' => $limit,
        'fundedUntil' => $until,
        'comment' => $comment,
        'ownerFundedAllowed' => true,
        'centralCredentialExposed' => false,
        'reportBackRequired' => true,
        'policyVersion' => 1
    ]);
} catch (Throwable $e) {
    error_log('gatewayAiPolicy.php: ' . $e->getMessage());
    replyAiPolicy(503, ['ok' => false, 'error' => 'ai_policy_unavailable']);
}
