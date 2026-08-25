<?php

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/hotspotApiCommon.php';
require_once dirname(__DIR__) . '/db_connect.php';

hotspotRequirePost();
$input = hotspotJsonInput();
$hotspot = hotspotAuthenticate($conn, $input);

$hostname = hotspotString($input, 'hostname', 255);
$installerVersion = hotspotString($input, 'installerVersion', 32);
$softwareVersion = hotspotString($input, 'softwareVersion', 64);
$hotspotIf = hotspotString($input, 'hotspotIf', 64);
$wanIf = hotspotString($input, 'wanIf', 64);
$ssid = hotspotString($input, 'ssid', 150);
$capabilities = $input['capabilities'] ?? [];
if (!is_array($capabilities)) $capabilities = [];
$seenIp = hotspotSeenIp();

try {
    $stmt = $conn->prepare(
        'UPDATE hotspotRegistry SET lastSeen=CURRENT_TIMESTAMP, seenIp=?, hostname=?, installerVersion=?, softwareVersion=?, hotspotIf=?, wanIf=?, ssid=?, capabilities=? WHERE hotspotId=?'
    );
    $stmt->execute([
        $seenIp, $hostname, $installerVersion, $softwareVersion, $hotspotIf, $wanIf,
        $ssid, json_encode($capabilities, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $hotspot['hotspotId'],
    ]);

    hotspotReply(200, [
        'ok' => true,
        'hotspotId' => $hotspot['publicId'],
        'seenIp' => $seenIp,
        'serverTime' => gmdate('c'),
        'locationPrecision' => $hotspot['locationPrecision'],
        'publicLocationPrecision' => $hotspot['publicLocationPrecision'],
    ]);
} catch (Throwable $e) {
    error_log('hotspotHeartbeat failed: ' . $e->getMessage());
    hotspotReply(500, ['ok' => false, 'error' => 'Heartbeat is temporarily unavailable']);
}
