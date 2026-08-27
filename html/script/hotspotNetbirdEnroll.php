<?php
// TaraSec hotspot bootstrap enrollment endpoint.
//
// Purpose:
//   - Accept a first-contact request from a new TaraSec hotspot.
//   - Ask the NetBird API for a ONE-OFF setup key.
//   - Auto-assign the peer to a restricted bootstrap group.
//   - Return only the one-off key and management URL to the caller.
//
// IMPORTANT SECURITY MODEL:
// The public installer has no pre-shared secret on first contact. Therefore
// peers enrolled through this endpoint MUST land in a restricted bootstrap
// group that has no lateral access to production peers. Promotion to broader
// management access must happen after TaraSec owner/device registration.
//
// Server-side configuration is read from:
//   /etc/tarasec/netbird-enrollment.env
// Never put the NetBird API token in this repository.

header('Content-Type: application/json');
header('Cache-Control: no-store');

function fail_json(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function load_env_file(string $path): array
{
    $cfg = [];
    if (!is_readable($path)) {
        return $cfg;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        $cfg[$key] = $value;
    }
    return $cfg;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail_json(405, 'POST required');
}

$cfg = load_env_file('/etc/tarasec/netbird-enrollment.env');
$apiUrl = rtrim($cfg['NB_API_URL'] ?? '', '/');
$apiToken = $cfg['NB_API_TOKEN'] ?? '';
$bootstrapGroup = $cfg['NB_BOOTSTRAP_GROUP_ID'] ?? '';
$managementUrl = $cfg['NB_MANAGEMENT_URL'] ?? '';

if ($apiUrl === '' || $apiToken === '' || $bootstrapGroup === '') {
    fail_json(503, 'Enrollment service is not configured');
}

$raw = file_get_contents('php://input');
$req = json_decode($raw ?: '', true);
if (!is_array($req)) {
    fail_json(400, 'Invalid JSON');
}

$deviceId = strtolower(trim((string)($req['device_id'] ?? '')));
$hostname = trim((string)($req['hostname'] ?? ''));
$arch = trim((string)($req['arch'] ?? ''));

if (!preg_match('/^[a-f0-9-]{32,64}$/', $deviceId)) {
    fail_json(400, 'Invalid device_id');
}
if ($hostname === '' || strlen($hostname) > 100 || !preg_match('/^[A-Za-z0-9._-]+$/', $hostname)) {
    fail_json(400, 'Invalid hostname');
}
if (strlen($arch) > 64) {
    fail_json(400, 'Invalid arch');
}

// Basic abuse throttle by source IP. This is not identity proof; it merely
// limits accidental or automated key harvesting. The bootstrap group remains
// the real containment boundary for first-contact peers.
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateDir = '/tmp/tarasec-netbird-enroll';
if (!is_dir($rateDir)) {
    @mkdir($rateDir, 0700, true);
}
$rateFile = $rateDir . '/' . hash('sha256', $remoteIp);
$now = time();
if (is_file($rateFile)) {
    $last = (int)@file_get_contents($rateFile);
    if ($last > 0 && ($now - $last) < 30) {
        fail_json(429, 'Enrollment rate limit exceeded');
    }
}
@file_put_contents($rateFile, (string)$now, LOCK_EX);

$keyName = 'tarasec-bootstrap-' . substr(str_replace('-', '', $deviceId), 0, 16);
$body = [
    'name' => $keyName,
    'type' => 'one-off',
    // NetBird currently requires at least 86400 seconds for API-created keys.
    'expires_in' => 86400,
    'auto_groups' => [$bootstrapGroup],
    'usage_limit' => 1,
    'ephemeral' => false,
    'allow_extra_dns_labels' => false,
];

$ch = curl_init($apiUrl . '/api/setup-keys');
if ($ch === false) {
    fail_json(500, 'Unable to initialize NetBird API request');
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Token ' . $apiToken,
    ],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlError !== '') {
    fail_json(502, 'NetBird API request failed');
}

$data = json_decode($response, true);
if ($status < 200 || $status >= 300 || !is_array($data)) {
    error_log('TaraSec NetBird enrollment API failure HTTP ' . $status . ': ' . substr($response, 0, 500));
    fail_json(502, 'NetBird refused setup-key creation');
}

$setupKey = (string)($data['key'] ?? '');
$keyId = (string)($data['id'] ?? '');
if ($setupKey === '') {
    fail_json(502, 'NetBird response did not contain a setup key');
}

error_log(sprintf(
    'TaraSec hotspot bootstrap issued: device=%s hostname=%s arch=%s source=%s key_id=%s',
    $deviceId,
    $hostname,
    $arch,
    $remoteIp,
    $keyId
));

echo json_encode([
    'ok' => true,
    'setup_key' => $setupKey,
    'management_url' => $managementUrl,
    'bootstrap_group' => true,
], JSON_UNESCAPED_SLASHES);
