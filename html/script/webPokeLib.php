<?php
// This demo deliberately has a fixed topology. Never accept a URL from a request.
const WEB_POKE_GATEWAY = '100.68.165.190';
const WEB_POKE_TTL = 1800;
function webPokeNodes(): array
{
    return [
        'roquefort' => ['name' => 'Roquefort', 'address' => '100.68.176.110'],
        'camembert' => ['name' => 'Camembert', 'address' => '100.68.149.164'],
        'dbserver1' => ['name' => 'dbserver1', 'address' => '100.68.126.0'],
        'gouda' => ['name' => 'Gouda', 'address' => '100.68.51.247'],
    ];
}
function webPokeToken(string $token): bool
{
    return preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1;
}
function webPokeEvidence(array $data, string $peer): array
{
    $age = (int)($data['trafficSecondsSince'] ?? -1);
    $valid = $peer === WEB_POKE_GATEWAY
        && ($data['senderIp'] ?? '') === $peer && $age >= 0 && $age <= 2
        && isset($data['trafficSeverity']) && is_numeric($data['trafficSeverity'])
        && (int)$data['trafficSeverity'] >= 0 && (int)$data['trafficSeverity'] <= 15;
    return [
        'state' => $valid ? ((int)$data['trafficSeverity'] > 1 ? 'infected' : 'clean') : 'unknown',
        'severity' => $valid ? (int)$data['trafficSeverity'] : null,
        'observed_at' => gmdate('c'),
        'source' => $valid ? 'traffic' : 'unverified',
        'message' => $valid ? 'Last direct Poke through Standard gateway'
            : ($peer !== WEB_POKE_GATEWAY ? 'This connection did not arrive through Standard gateway' : 'No fresh traffic tag for this connection; retry Poke'),
    ];
}
function webPokeDirectory(): string
{
    $dir = sys_get_temp_dir() . '/tarasec-web-poke';
    if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create demo observation directory');
    }
    return $dir;
}
function webPokeSnapshot(string $token, ?array $observation = null): array
{
    if (!webPokeToken($token)) throw new InvalidArgumentException('Invalid demo session');
    $dir = webPokeDirectory();
    $path = $dir . '/' . $token . '.json';
    if ($observation === null && !is_file($path)) return ['state' => 'unknown', 'message' => 'Not yet poked'];
    if ($observation !== null && !is_file($path)) {
        // Bound storage and reclaim abandoned sessions, without touching security records.
        $files = glob($dir . '/*.json') ?: [];
        foreach ($files as $file) {
            if (filemtime($file) < time() - WEB_POKE_TTL) @unlink($file);
        }
        if (count(glob($dir . '/*.json') ?: []) >= 2048) throw new RuntimeException('Demo observation capacity reached');
    }
    $fp = fopen($path, $observation === null ? 'r' : 'c+');
    if (!$fp) throw new RuntimeException('Unable to open demo observation');
    try {
        if (!flock($fp, $observation === null ? LOCK_SH : LOCK_EX)) throw new RuntimeException('Unable to lock demo observation');
        $previous = json_decode(stream_get_contents($fp), true) ?: [];
        $created = (int)($previous['created'] ?? time());
        if ($created + WEB_POKE_TTL <= time()) return ['state' => 'unknown', 'message' => 'Session expired; start again'];
        if ($observation === null) return $previous;
        $observation['created'] = $created;
        $observation['expires_at'] = $created + WEB_POKE_TTL;
        rewind($fp);
        if (!ftruncate($fp, 0) || fwrite($fp, json_encode($observation, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Unable to save demo observation');
        }
        return $observation;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
