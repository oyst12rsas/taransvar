<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$name = gethostname() ?: 'TaraSec hotspot';

$configPaths = [
    '/etc/config/opennds',
    '/etc/opennds/opennds.conf'
];

foreach ($configPaths as $path) {
    if (!is_readable($path)) continue;
    $text = @file_get_contents($path);
    if ($text === false) continue;

    if (preg_match("/^\\s*option\\s+gatewayname\\s+'([^']+)'/mi", $text, $m)) {
        $candidate = trim($m[1]);
        if ($candidate !== '') {
            $name = $candidate;
            break;
        }
    }
    if (preg_match('/^\\s*GatewayName\\s+(.+)$/mi', $text, $m)) {
        $candidate = trim($m[1], " \t\r\n\"'");
        if ($candidate !== '') {
            $name = $candidate;
            break;
        }
    }
}

$gatewayKey='';
$gatewayKeyFile='/etc/tarasec/gateway-public-key';
if (is_readable($gatewayKeyFile)) {
    $gatewayKey=trim((string)file_get_contents($gatewayKeyFile));
}

echo json_encode([
    'ok' => true,
    'name' => $name,
    'role' => 'tarasec-hotspot',
    'service' => 'tarasec',
    'gateway_key' => $gatewayKey,
    'server_time' => gmdate('c')
], JSON_UNESCAPED_SLASHES);
