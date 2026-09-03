<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'reason'=>'post_required']);
    exit;
}
$code=trim((string)($_POST['code'] ?? ''));
$clientIp=(string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/',$code)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'reason'=>'invalid_code']);
    exit;
}
$command='/usr/bin/sudo -n /usr/local/sbin/tarasec-global-bind '
    .escapeshellarg($code).' '.escapeshellarg($clientIp).' 2>&1';
$output=[]; $status=1;
exec($command,$output,$status);
$body=implode("\n",$output);
if ($status!==0 || $body==='') {
    http_response_code(502);
    echo json_encode(['ok'=>false,'reason'=>'gateway_binding_failed']);
    exit;
}
echo $body;
