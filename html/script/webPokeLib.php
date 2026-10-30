<?php
const WEB_POKE_TTL = 1800;
function webPokeNodes(): array
{
    // Receiver selection belongs to the DB coordinator, never to a browser.
    return [
        'standard' => ['name'=>'Standard gateway', 'address'=>'100.68.165.190'],
        'roquefort' => ['name'=>'Roquefort', 'address'=>'100.68.176.110'],
        'camembert' => ['name'=>'Camembert', 'address'=>'100.68.149.164'],
        'dbserver1' => ['name'=>'dbserver1', 'address'=>'100.68.126.0'],
        'gouda' => ['name'=>'Gouda', 'address'=>'100.68.51.247'],
    ];
}
function webPokeToken(string $token): bool { return preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1; }
function webPokeIp(string $ip): bool
{
    $n = ip2long($ip);
    return $n !== false && ($n & 0xffc00000) === ip2long('100.64.0.0');
}
function webPokeConfig(string $role): array
{
    $path = '/etc/tarasec-web-poke-' . $role . '.conf';
    $config = is_readable($path) ? parse_ini_file($path, false, INI_SCANNER_RAW) : false;
    if (!is_array($config)) throw new RuntimeException('Web demo setup is missing on this node');
    return $config;
}
function webPokeAllowed(array $config, string $gateway): bool
{
    if (!webPokeIp($gateway)) return false;
    // Missing/invalid values fail closed; YES is an explicit installation choice.
    if (!in_array($config['ALLOW_ANY_GATEWAY'] ?? 'NO', ['YES','NO'], true)) return false;
    $allowed = array_filter(array_map('trim', explode(',', (string)($config['ALLOWED_GATEWAYS'] ?? ''))));
    return ($config['ALLOW_ANY_GATEWAY'] ?? 'NO') === 'YES' || in_array($gateway, $allowed, true);
}
function webPokeRequireAllowed(array $config, string $gateway): void
{
    if (!webPokeAllowed($config, $gateway)) throw new DomainException('This gateway is not permitted to use the demo');
}
function webPokeEvidence(array $data, string $peer, string $expectedGateway): array
{
    $age = (int)($data['trafficSecondsSince'] ?? -1);
    $valid = $peer === $expectedGateway && ($data['senderIp'] ?? '') === $peer && $age >= 0 && $age <= 2
        && isset($data['trafficSeverity']) && is_numeric($data['trafficSeverity'])
        && (int)$data['trafficSeverity'] >= 0 && (int)$data['trafficSeverity'] <= 15;
    return ['state'=>$valid ? ((int)$data['trafficSeverity'] > 1 ? 'infected' : 'clean') : 'unknown',
        'severity'=>$valid ? (int)$data['trafficSeverity'] : null, 'observed_at'=>gmdate('c'),
        'source'=>$valid ? 'traffic' : 'unverified',
        'message'=>$valid ? 'Last direct Poke through your gateway' : 'No fresh traffic tag for this authorized connection; retry Poke'];
}
function webPokeDirectory(): string
{
    $dir = sys_get_temp_dir() . '/tarasec-web-poke-v2';
    if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) throw new RuntimeException('Demo storage unavailable');
    return $dir;
}
// Private, bounded session storage; all read/modify/write operations are locked.
function webPokeRecord(string $kind, string $token, ?callable $update = null): array
{
    if (!preg_match('/\A[a-z]+\z/D', $kind) || !webPokeToken($token)) throw new InvalidArgumentException('Invalid demo session');
    $dir = webPokeDirectory(); $path = $dir . '/' . $kind . '-' . hash('sha256', $token) . '.json';
    if (!is_file($path) && $update === null) throw new DomainException('Demo session unavailable or expired');
    if (!is_file($path) && $update !== null) {
        $files = glob($dir . '/*.json') ?: [];
        foreach ($files as $file) if (@filemtime($file) < time() - WEB_POKE_TTL - 60) @unlink($file);
        if (count(glob($dir . '/*.json') ?: []) > 8192) throw new RuntimeException('Demo session capacity reached');
    }
    $fp = fopen($path, $update ? 'c+' : 'r');
    if (!$fp) throw new RuntimeException('Demo storage unavailable');
    try {
        if (!flock($fp, $update ? LOCK_EX : LOCK_SH)) throw new RuntimeException('Demo lock unavailable');
        $row = json_decode(stream_get_contents($fp), true) ?: [];
        if ($update) {
            $row = $update($row);
            $json = json_encode($row, JSON_THROW_ON_ERROR);
            rewind($fp);
            if (!ftruncate($fp, 0) || fwrite($fp, $json) !== strlen($json)) throw new RuntimeException('Demo storage failed');
            fflush($fp);
        }
        return $row;
    } finally { flock($fp, LOCK_UN); fclose($fp); }
}
function webPokeActive(array $session): void
{
    if ((int)($session['expires'] ?? 0) <= time() || ($session['revoked'] ?? false)) throw new DomainException('Demo session unavailable or expired');
}
function webPokeRate(string $scope, string $peer, int $maximum, int $seconds): void
{
    webPokeRecord('rate', hash('sha256', $scope . $peer), function ($row) use ($maximum, $seconds) {
        if ((int)($row['until'] ?? 0) <= time()) $row = ['count'=>0,'until'=>time()+$seconds];
        if ((int)$row['count'] >= $maximum) throw new DomainException('Demo request limit reached; try again later');
        ++$row['count']; return $row;
    });
}
function webPokeSeal(array $data, string $key): array
{
    if (!webPokeToken($key)) throw new RuntimeException('Demo authentication is not configured');
    $data['issued_at'] = time(); $data['nonce'] = bin2hex(random_bytes(32));
    $payload = base64_encode(json_encode($data, JSON_THROW_ON_ERROR));
    return ['payload'=>$payload, 'signature'=>hash_hmac('sha256', $payload, $key)];
}
function webPokeVerify(array $packet, string $key, bool $consumeNonce = true): array
{
    $payload = (string)($packet['payload'] ?? ''); $signature = (string)($packet['signature'] ?? '');
    if (!webPokeToken($key) || !webPokeToken($signature) || strlen($payload) > 32768
        || !hash_equals(hash_hmac('sha256', $payload, $key), $signature)) throw new DomainException('Unauthenticated demo message');
    $data = json_decode((string)base64_decode($payload, true), true);
    if (!is_array($data) || abs(time() - (int)($data['issued_at'] ?? 0)) > 30 || !webPokeToken((string)($data['nonce'] ?? '')))
        throw new DomainException('Expired demo message');
    if ($consumeNonce) webPokeRecord('nonce', hash('sha256', $key . $data['nonce']), function ($row) {
        if ($row) throw new DomainException('Replayed demo message');
        return ['seen'=>time()];
    });
    return $data;
}
function webPokeHttp(string $ip, string $endpoint, array $body): array
{
    if (!webPokeIp($ip) || !in_array($endpoint, ['webPokeCoordinator.php','webPokeGateway.php','webPokeReceiver.php','webPokeEnrollment.php'], true))
        throw new InvalidArgumentException('Invalid demo destination');
    $ch = curl_init('http://' . $ip . '/script/' . $endpoint);
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_PROXY=>'',
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTP, CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_TIMEOUT=>8]);
    $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if ($status === 403) throw new DomainException('Demo access denied by the DB server');
    if ($status < 200 || $status >= 300 || !is_array($data) || ($data['ok'] ?? false) !== true)
        throw new RuntimeException('Demo service unavailable');
    return $data;
}
function webPokeInput(): array
{
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) throw new InvalidArgumentException('Request too large');
    return str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
        ? (json_decode((string)file_get_contents('php://input', false, null, 0, 32769), true) ?: []) : $_POST;
}
function webPokeReply(int $status, array $body): never
{
    http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES); exit;
}
function webPokeError(Throwable $e): never
{
    if ($e instanceof DomainException) webPokeReply(403, ['ok'=>false,'error'=>$e->getMessage()]);
    if ($e instanceof InvalidArgumentException) webPokeReply(400, ['ok'=>false,'error'=>$e->getMessage()]);
    error_log('Web Poke: ' . $e->getMessage()); webPokeReply(503, ['ok'=>false,'error'=>'Demo service unavailable; check node setup']);
}

function webPokeIsLocal(string $peer, string $address, string $mask): bool
{
    $ip = ip2long($peer); $network = ip2long($address); $bits = ip2long($mask);
    if ($ip === false || $network === false || $bits === false || $bits === 0) return false;
    $inverse = (~$bits) & 0xffffffff;
    if (($inverse & ($inverse + 1)) !== 0) return false; // Reject non-contiguous masks.
    return ($ip & $bits) === ($network & $bits);
}
function webPokeReceiversFor(string $gateway): array
{
    return array_filter(webPokeNodes(), fn($node) => $node['address'] !== $gateway);
}
function webPokeBootstrapProof(array $pending, string $peer, string $proof): array
{
    if ($peer !== ($pending['db_ip'] ?? '') || !webPokeToken($proof)
        || !hash_equals((string)($pending['proof_hash'] ?? ''), hash('sha256', $proof))
        || (int)($pending['expires'] ?? 0) <= time() || !webPokeToken((string)($pending['public_key'] ?? '')))
        throw new DomainException('No matching local administrator enrollment');
    return ['ok'=>true,'public_key'=>$pending['public_key']];
}
function webPokeEnrollReceiver(array $config, string $peer, array $input, callable $rpc): array
{
    $matches = array_filter(webPokeNodes(), fn($node) => $node['address'] === $peer);
    if (!$matches) throw new DomainException('Receiver is not in the DB participant list');
    $id = array_key_first($matches); $proof = (string)($input['proof'] ?? '');
    if (!webPokeToken($proof)) throw new DomainException('Local administrator enrollment required');
    webPokeRate('enroll', $peer, 5, 60);
    // Never accept the encryption key from the initiating request. Fetch it from
    // a root-created, short-lived bootstrap on the node at the actual socket peer.
    $identity = $rpc($peer, 'webPokeEnrollment.php', ['proof'=>$proof]);
    if (!webPokeToken((string)($identity['public_key'] ?? ''))) throw new DomainException('Invalid administrator enrollment key');
    $key = (string)($config['KEY_'.strtoupper($id)] ?? '');
    if (!webPokeToken($key)) throw new RuntimeException('Update the DB demo setup first');
    $payload = json_encode(['NODE_ID'=>$id,'AUTH_KEY'=>$key], JSON_THROW_ON_ERROR);
    return ['ok'=>true,'sealed_config'=>base64_encode(sodium_crypto_box_seal($payload, hex2bin($identity['public_key'])))];
}
function webPokeDetectNode(array $setup, array $addresses): array
{
    $isDb = (int)($setup['is_db'] ?? 0) === 1;
    $dbIp = (string)($setup['db_ip'] ?? '');
    $nodeId = '';
    foreach (webPokeNodes() as $id=>$node) if (in_array($node['address'], $addresses, true)) $nodeId=$id;
    if (!webPokeIp($dbIp) && $isDb && $nodeId !== '') $dbIp=webPokeNodes()[$nodeId]['address'];
    if (!webPokeIp($dbIp)) throw new RuntimeException('No valid global DB address is configured in setup');
    if ($isDb) {
        foreach (webPokeNodes() as $id=>$node) if ($node['address']===$dbIp) $nodeId=$id;
        if ($nodeId==='' || webPokeNodes()[$nodeId]['address']!==$dbIp) throw new RuntimeException('The DB server is missing from the approved participant list');
    }
    return ['is_db'=>$isDb,'db_ip'=>$dbIp,'node_id'=>$nodeId];
}
