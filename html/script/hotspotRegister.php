<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/hotspotApiCommon.php';
require_once dirname(__DIR__) . '/db_connect.php';

hotspotRequirePost();
$input = hotspotJsonInput();

$publicKey = hotspotString($input, 'publicKey', 4096);
if ($publicKey === '') hotspotReply(400, ['ok' => false, 'error' => 'publicKey is required']);
$publicKeyHash = hash('sha256', preg_replace('/\s+/', '', $publicKey));

$hostname = hotspotString($input, 'hostname', 255);
$installerVersion = hotspotString($input, 'installerVersion', 32);
$softwareVersion = hotspotString($input, 'softwareVersion', 64);
$hotspotIf = hotspotString($input, 'hotspotIf', 64);
$wanIf = hotspotString($input, 'wanIf', 64);
$ssid = hotspotString($input, 'ssid', 150);
$capabilities = $input['capabilities'] ?? [];
if (!is_array($capabilities)) $capabilities = [];
$capabilitiesJson = json_encode($capabilities, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$seenIp = hotspotSeenIp();

try {
    $stmt = $conn->prepare('SELECT publicId FROM hotspotRegistry WHERE publicKeyHash = ? LIMIT 1');
    $stmt->execute([$publicKeyHash]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        hotspotReply(409, [
            'ok' => false,
            'error' => 'Hotspot identity is already registered. Use the stored API token; re-registration never rotates credentials automatically.',
            'hotspotId' => $existing['publicId'],
        ]);
    }

    $apiToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $apiTokenHash = hash('sha256', $apiToken);
    $publicId = 'HS-' . strtoupper(bin2hex(random_bytes(6)));

    $stmt = $conn->prepare(
        'INSERT INTO hotspotRegistry (publicId, lastSeen, publicKey, publicKeyHash, apiTokenHash, hostname, installerVersion, softwareVersion, seenIp, hotspotIf, wanIf, ssid, capabilities) '
        . 'VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $publicId, $publicKey, $publicKeyHash, $apiTokenHash, $hostname,
        $installerVersion, $softwareVersion, $seenIp, $hotspotIf, $wanIf,
        $ssid, $capabilitiesJson,
    ]);

    hotspotReply(201, [
        'ok' => true,
        'hotspotId' => $publicId,
        'apiToken' => $apiToken,
        'seenIp' => $seenIp,
        'registeredAt' => gmdate('c'),
        'location' => [
            'precision' => 'none',
            'publicPrecision' => 'none',
            'message' => 'Location is optional and can be added later by the owner.',
        ],
    ]);
} catch (Throwable $e) {
    error_log('hotspotRegister failed: ' . $e->getMessage());
    hotspotReply(500, ['ok' => false, 'error' => 'Hotspot registration is temporarily unavailable']);
}
