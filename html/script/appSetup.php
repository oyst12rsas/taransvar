<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

include 'getSenderIp.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$sender = getSenderIp();
if (strncasecmp($sender, '::ffff:', 7) === 0) {
    $sender = substr($sender, 7);
}

if (!filter_var($sender, FILTER_VALIDATE_IP)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unable to determine sender IP']);
    exit;
}

echo json_encode([
    'ok' => true,
    'gateway_ip' => $sender,
    'seen_ip' => $sender,
    'server_time' => gmdate('c')
], JSON_UNESCAPED_SLASHES);
