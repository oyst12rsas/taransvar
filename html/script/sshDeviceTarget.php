<?php
declare(strict_types=1);
// Public enrollment identity only. No manager cookie, state, keys or SSH controls.
require_once __DIR__.'/managerSshApprovalTarget.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'get_required']);
    exit;
}
try {
    $target=managerSshApprovalTargetRead();
    if ($target===null) throw new RuntimeException('not enrolled');
    echo json_encode(['ok'=>true,'approvalTarget'=>$target],JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'ssh_device_identity_unavailable']);
}
