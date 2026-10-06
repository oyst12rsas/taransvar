<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__.'/serviceDiscoveryCommon.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok'=>false,'error'=>'get_required']); exit;
    }
    echo json_encode(['ok'=>true,'service'=>'tarasec','version'=>1,'account_services'=>taraAccountServices(),'admin_services'=>taraAdminServices()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('TaraSec service discovery configuration unavailable');
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'service_discovery_not_configured']);
}
