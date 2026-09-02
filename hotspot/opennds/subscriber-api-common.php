<?php
// Shared helpers for TaraSec subscriber-facing APIs.

declare(strict_types=1);

function subscriber_reply(int $code, array $body): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function subscriber_db(): mysqli {
    $dbBootstrap = getenv('TARASEC_DB_BOOTSTRAP') ?: dirname(__DIR__, 3) . '/html/db_connect.php';
    if (!is_file($dbBootstrap)) subscriber_reply(500, ['ok'=>false,'reason'=>'db_bootstrap_missing']);
    require $dbBootstrap;
    $handle = $mysqli ?? $conn ?? $db ?? null;
    if (!($handle instanceof mysqli)) subscriber_reply(500, ['ok'=>false,'reason'=>'db_handle_missing']);
    return $handle;
}

function subscriber_token(): string {
    $token = trim((string)($_SERVER['HTTP_X_TARASEC_SUBSCRIBER_TOKEN'] ?? ''));
    if ($token !== '') return $token;
    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) return trim($m[1]);
    return '';
}

function subscriber_require(mysqli $db): array {
    $token = subscriber_token();
    if ($token === '') subscriber_reply(401, ['ok'=>false,'reason'=>'subscriber_token_missing']);
    $hash = hash('sha256', $token);
    $stmt = $db->prepare("SELECT t.subscriberTokenId,t.customerId,c.email,c.phone,c.active
                          FROM hotspotSubscriberToken t
                          JOIN hotspotCustomer c ON c.customerId=t.customerId
                          WHERE t.tokenHash=? AND t.revokedAt IS NULL AND t.expiresAt>NOW()
                            AND c.active=b'1' LIMIT 1");
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) subscriber_reply(401, ['ok'=>false,'reason'=>'subscriber_token_invalid']);
    $id=(int)$row['subscriberTokenId'];
    $db->query('UPDATE hotspotSubscriberToken SET lastUsed=NOW() WHERE subscriberTokenId='.$id);
    return $row;
}
