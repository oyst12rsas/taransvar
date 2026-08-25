<?php

declare(strict_types=1);

function hotspotJsonInput(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function hotspotReply(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function hotspotRequirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        hotspotReply(405, ['ok' => false, 'error' => 'POST required']);
    }
}

function hotspotSeenIp(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (str_starts_with($ip, '::ffff:')) $ip = substr($ip, 7);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function hotspotString(array $input, string $key, int $max = 255): string
{
    $v = trim((string)($input[$key] ?? ''));
    if (mb_strlen($v) > $max) $v = mb_substr($v, 0, $max);
    return $v;
}

function hotspotTokenFromRequest(array $input): string
{
    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) return trim($m[1]);
    return hotspotString($input, 'apiToken', 256);
}

function hotspotAuthenticate(PDO $conn, array $input): array
{
    $publicId = hotspotString($input, 'hotspotId', 40);
    $token = hotspotTokenFromRequest($input);
    if ($publicId === '' || $token === '') {
        hotspotReply(401, ['ok' => false, 'error' => 'Hotspot authentication required']);
    }

    $stmt = $conn->prepare('SELECT hotspotId, publicId, apiTokenHash, locationPrecision, publicLocationPrecision FROM hotspotRegistry WHERE publicId = ? LIMIT 1');
    $stmt->execute([$publicId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !hash_equals((string)$row['apiTokenHash'], hash('sha256', $token))) {
        hotspotReply(401, ['ok' => false, 'error' => 'Invalid hotspot credentials']);
    }
    return $row;
}

function hotspotPrecisionRank(string $precision): int
{
    static $ranks = [
        'none' => 0,
        'country' => 1,
        'region' => 2,
        'city' => 3,
        'postcode' => 4,
        'approximate' => 5,
        'exact' => 6,
    ];
    return $ranks[$precision] ?? -1;
}
