<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function overviewReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function startOverviewManagerSession(): void
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

function decodeStatus(?string $raw): array
{
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

startOverviewManagerSession();
if (empty($_SESSION['tarasec_manager_authenticated'])) {
    overviewReply(401, ['ok' => false, 'error' => 'manager_session_required']);
}

try {
    $conn = getConnection();
    $requestId = (int)($_SESSION['tarasec_manager_request_id'] ?? 0);
    if ($requestId <= 0) {
        $conn->close();
        overviewReply(401, ['ok' => false, 'error' => 'manager_session_invalid']);
    }

    $stmt = $conn->prepare("SELECT 1 FROM managerRequest WHERE managerRequestId=? AND active=b'1' AND rejectedTime IS NULL AND (expires IS NULL OR expires>NOW()) LIMIT 1");
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $activeManager = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$activeManager) {
        $conn->close();
        overviewReply(401, ['ok' => false, 'error' => 'manager_access_no_longer_active']);
    }

    $local = [
        'name' => 'Gateway',
        'ip' => '',
        'secondsSince' => null,
        'status' => []
    ];
    $result = $conn->query("SELECT COALESCE(nickname,'Gateway') AS name, INET_NTOA(adminIP) AS ip, networkStatus, TIMESTAMPDIFF(SECOND, networkStatusChecked, NOW()) AS secondsSince FROM setup LIMIT 1");
    if ($row = $result->fetch_assoc()) {
        $local = [
            'name' => (string)$row['name'],
            'ip' => (string)($row['ip'] ?? ''),
            'secondsSince' => isset($row['secondsSince']) ? (int)$row['secondsSince'] : null,
            'status' => decodeStatus($row['networkStatus'] ?? null)
        ];
    }
    $result->free();

    $sites = [];
    $sql = "SELECT R.routerId, P.name, INET_NTOA(R.ip) AS ip, R.status, TIMESTAMPDIFF(SECOND, R.partnerStatusReceived, NOW()) AS secondsSince FROM partnerRouter R JOIN partner P ON P.partnerId=R.partnerId WHERE R.showToAdminsOnly=b'0' ORDER BY P.name";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $sites[] = [
            'routerId' => (int)$row['routerId'],
            'name' => (string)$row['name'],
            'ip' => (string)$row['ip'],
            'secondsSince' => isset($row['secondsSince']) ? (int)$row['secondsSince'] : null,
            'status' => decodeStatus($row['status'] ?? null)
        ];
    }
    $result->free();

    $units = [];
    // dhcpClientState is present on current schemas and gives the useful active-unit
    // columns without requiring the App to scrape the Gatekeeper HTML table.
    $result = $conn->query("SELECT clientMac, INET_NTOA(currentIp) AS currentIp, COALESCE(hostname,'') AS hostname, COALESCE(vendorClass,'') AS vendorClass, lastSeen FROM dhcpClientState WHERE lastSeen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY lastSeen DESC LIMIT 100");
    while ($row = $result->fetch_assoc()) {
        $units[] = [
            'hostname' => (string)$row['hostname'],
            'vendor' => (string)$row['vendorClass'],
            'mac' => (string)$row['clientMac'],
            'lastSeen' => (string)$row['lastSeen'],
            'lastIp' => (string)($row['currentIp'] ?? '')
        ];
    }
    $result->free();

    $conn->close();
    overviewReply(200, [
        'ok' => true,
        'local' => $local,
        'sites' => $sites,
        'activeUnits' => $units,
        'server_time' => gmdate('c')
    ]);
} catch (Throwable $e) {
    error_log('managerOverview.php: '.$e->getMessage());
    overviewReply(503, ['ok' => false, 'error' => 'manager_overview_unavailable']);
}
