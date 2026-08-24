<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
include '../dbfunc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}
function startManagerSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
}

startManagerSession();
if (empty($_SESSION['tarasec_manager_authenticated'])) reply(401, ['ok'=>false,'error'=>'manager_session_required']);

try {
    $conn = getConnection();
    $requestId = (int)($_SESSION['tarasec_manager_request_id'] ?? 0);
    $stmt = $conn->prepare("SELECT 1 FROM managerRequest WHERE managerRequestId=? AND active=b'1' AND rejectedTime IS NULL AND (expires IS NULL OR expires>NOW()) LIMIT 1");
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $active = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$active) { $conn->close(); reply(401, ['ok'=>false,'error'=>'manager_access_no_longer_active']); }

    $hotspot = false;
    $ssid = '';
    $result = $conn->query("SELECT CAST(hotspot AS UNSIGNED) AS hotspot, COALESCE(ssid,'') AS ssid FROM setup LIMIT 1");
    if ($row = $result->fetch_assoc()) {
        $hotspot = ((int)$row['hotspot'] === 1);
        $ssid = (string)$row['ssid'];
    }
    $result->free();
    $conn->close();

    reply(200, [
        'ok'=>true,
        'capabilities'=>[
            'cybersecurity'=>true,
            'hotspot'=> $hotspot,
            'hotspot_monitoring'=> $hotspot,
        ],
        'hotspot'=> $hotspot ? ['ssid'=>$ssid,'endpoint'=>'/script/managerHotspot.php'] : null,
        'server_time'=>gmdate('c')
    ]);
} catch (Throwable $e) {
    error_log('managerCapabilities.php: '.$e->getMessage());
    reply(503, ['ok'=>false,'error'=>'manager_capabilities_unavailable']);
}
