<?php
/**
 * TaraSec managed hotspot bootstrap API.
 *
 * The caller supplies a one-time TaraSec enrollment token plus owner contact
 * details. This endpoint consumes the token, stores the installation for
 * back-office follow-up, creates one-off NetBird/Wazuh bootstrap credentials,
 * and leaves global-DB forwarding to the retry worker.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_connect.php';

function failJson(int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function managedConfig(): array {
    $path = '/etc/tarasec-managed-server.conf';
    if (!is_readable($path)) throw new RuntimeException('Managed enrollment is not configured on this server.');
    $cfg = parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($cfg)) throw new RuntimeException('Unable to parse managed enrollment configuration.');
    return $cfg;
}

function cfg(array $cfg, string $key, ?string $default = null): string {
    $v = trim((string)($cfg[$key] ?? $default ?? ''));
    if ($v === '' && $default === null) throw new RuntimeException("Missing server configuration: $key");
    return $v;
}

function httpJson(string $method, string $url, array $headers = [], ?array $body = null, bool $verifyTls = true, ?string $caFile = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_SSL_VERIFYPEER => $verifyTls,
        CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
    ];
    if ($caFile && is_readable($caFile)) $opts[CURLOPT_CAINFO] = $caFile;
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Upstream HTTP error: ' . $err);
    }
    curl_close($ch);
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = ['raw' => $raw];
    if ($status < 200 || $status >= 300) throw new RuntimeException("Upstream returned HTTP $status");
    return $data;
}

function createNetbirdKey(array $cfg, int $installationId): array {
    $base = rtrim(cfg($cfg, 'NETBIRD_API_BASE'), '/');
    $token = cfg($cfg, 'NETBIRD_API_TOKEN');
    $group = cfg($cfg, 'NETBIRD_HOTSPOT_GROUP_ID');
    $reply = httpJson('POST', $base . '/setup-keys', ['Authorization: Token ' . $token], [
        'name' => 'tarasec-hotspot-' . $installationId,
        'type' => 'one-off',
        'expires_in' => 86400,
        'auto_groups' => [$group],
        'usage_limit' => 1,
        'ephemeral' => false,
        'allow_extra_dns_labels' => false,
    ]);
    if (empty($reply['key'])) throw new RuntimeException('NetBird did not return a setup key.');
    return $reply;
}

function wazuhJwt(array $cfg): string {
    $base = rtrim(cfg($cfg, 'WAZUH_API_BASE'), '/');
    $user = cfg($cfg, 'WAZUH_API_USER');
    $pass = cfg($cfg, 'WAZUH_API_PASSWORD');
    $verify = cfg($cfg, 'WAZUH_VERIFY_TLS', '1') !== '0';
    $ca = trim((string)($cfg['WAZUH_CA_FILE'] ?? '')) ?: null;
    $ch = curl_init($base . '/security/user/authenticate?raw=true');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    ]);
    if ($ca && is_readable($ca)) curl_setopt($ch, CURLOPT_CAINFO, $ca);
    $jwt = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($jwt === false) {
        $err = curl_error($ch); curl_close($ch); throw new RuntimeException('Wazuh authentication failed: ' . $err);
    }
    curl_close($ch);
    $jwt = trim((string)$jwt);
    if ($status < 200 || $status >= 300 || $jwt === '') throw new RuntimeException('Wazuh API authentication failed.');
    return $jwt;
}

function createWazuhAgent(array $cfg, int $installationId, string $hostname): array {
    $base = rtrim(cfg($cfg, 'WAZUH_API_BASE'), '/');
    $verify = cfg($cfg, 'WAZUH_VERIFY_TLS', '1') !== '0';
    $ca = trim((string)($cfg['WAZUH_CA_FILE'] ?? '')) ?: null;
    $safe = preg_replace('/[^A-Za-z0-9_.-]/', '-', $hostname) ?: 'hotspot';
    $name = substr('tarasec-' . $installationId . '-' . $safe, 0, 120);
    $reply = httpJson('POST', $base . '/agents', ['Authorization: Bearer ' . wazuhJwt($cfg)], [
        'name' => $name,
        'ip' => 'any',
    ], $verify, $ca);
    $data = $reply['data'] ?? [];
    $id = $data['id'] ?? ($data['affected_items'][0]['id'] ?? null);
    $key = $data['key'] ?? ($data['affected_items'][0]['key'] ?? null);
    if (!$id || !$key) throw new RuntimeException('Wazuh did not return an agent id/key.');
    return ['id' => (string)$id, 'key' => (string)$key, 'name' => $name];
}

function newUuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') failJson(405, 'POST required');
$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) failJson(400, 'Invalid JSON');
$token = trim((string)($input['enrollment_token'] ?? ''));
$hostname = substr(trim((string)($input['hostname'] ?? 'hotspot')), 0, 255);
$country = strtoupper(substr(trim((string)($input['country'] ?? '')), 0, 2));
$machineId = substr(trim((string)($input['machine_id'] ?? '')), 0, 128);
$ownerName = substr(trim((string)($input['owner_name'] ?? '')), 0, 255);
$ownerEmail = strtolower(substr(trim((string)($input['owner_email'] ?? '')), 0, 255));
$ownerPhone = substr(trim((string)($input['owner_phone'] ?? '')), 0, 64);
if ($token === '') failJson(400, 'Missing enrollment_token');
if ($ownerName === '') failJson(400, 'Owner name is required');
if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) failJson(400, 'Valid owner email is required');
if (!preg_match('/^[+0-9][0-9 ()-]{6,30}$/', $ownerPhone)) failJson(400, 'Valid owner phone number is required');

try {
    $cfg = managedConfig();
    $hash = hash('sha256', $token);
    $conn->beginTransaction();
    $stmt = $conn->prepare('SELECT * FROM managedEnrollmentToken WHERE tokenHash=? AND usedTime IS NULL AND expires>NOW() FOR UPDATE');
    $stmt->execute([$hash]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) { $conn->rollBack(); failJson(403, 'Enrollment token invalid, expired, or already used'); }
    if (strtolower(trim((string)$ticket['ownerEmail'])) !== $ownerEmail) {
        $conn->rollBack();
        failJson(403, 'Owner email does not match the TaraSec enrollment invitation');
    }

    $paymentAvailable = cfg($cfg, 'PAYMENT_AVAILABLE', '1') === '1' ? 1 : 0;
    $uuid = newUuid();
    $stmt = $conn->prepare('INSERT INTO managedInstallation (installationUuid,created,lastSeen,ownerName,ownerEmail,ownerPhone,hostname,country,machineId,paymentAvailable) VALUES (?,NOW(),NOW(),?,?,?,?,?,?,?)');
    $stmt->execute([$uuid, $ownerName, $ownerEmail, $ownerPhone, $hostname, $country ?: null, $machineId ?: null, $paymentAvailable]);
    $installationId = (int)$conn->lastInsertId();

    $stmt = $conn->prepare('UPDATE managedEnrollmentToken SET usedTime=NOW(), managedInstallationId=? WHERE managedEnrollmentTokenId=?');
    $stmt->execute([$installationId, $ticket['managedEnrollmentTokenId']]);
    $conn->commit();

    $nb = createNetbirdKey($cfg, $installationId);
    $wz = createWazuhAgent($cfg, $installationId, $hostname);

    $stmt = $conn->prepare('UPDATE managedInstallation SET netbirdSetupKeyId=?, wazuhAgentId=?, wazuhAgentName=? WHERE managedInstallationId=?');
    $stmt->execute([(string)($nb['id'] ?? ''), $wz['id'], $wz['name'], $installationId]);

    echo json_encode([
        'ok' => true,
        'installation_id' => $installationId,
        'installation_uuid' => $uuid,
        'owner' => ['name' => $ownerName, 'email' => $ownerEmail, 'phone' => $ownerPhone],
        'netbird' => [
            'management_url' => cfg($cfg, 'NETBIRD_MANAGEMENT_URL'),
            'setup_key' => $nb['key'],
        ],
        'soc' => [
            'provider' => 'wazuh',
            'manager' => cfg($cfg, 'WAZUH_MANAGER'),
            'agent_id' => $wz['id'],
            'agent_name' => $wz['name'],
            'agent_key' => $wz['key'],
        ],
        'payment' => [
            'available' => (bool)$paymentAvailable,
            'contact_url' => cfg($cfg, 'PAYMENT_CONTACT_URL', 'https://tarasec.org/'),
        ],
        'global_registration' => ['queued' => true],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('managedEnroll: ' . $e->getMessage());
    failJson(500, 'Managed enrollment failed; contact TaraSec support with the installation time.');
}
