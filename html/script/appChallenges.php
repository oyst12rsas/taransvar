<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
include '../dbfunc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function challengeReply(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ipInCidr(string $ip, string $cidr): bool {
    $cidr = trim($cidr);
    if ($cidr === '' || $cidr === '*') return $cidr === '*';
    $parts = explode('/', $cidr, 2);
    $network = $parts[0];
    if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($network, FILTER_VALIDATE_IP)) return false;
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($network);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
    $maxBits = strlen($ipBin) * 8;
    $prefix = isset($parts[1]) ? (int)$parts[1] : $maxBits;
    if ($prefix < 0 || $prefix > $maxBits) return false;
    $whole = intdiv($prefix, 8);
    $remain = $prefix % 8;
    if ($whole > 0 && substr($ipBin, 0, $whole) !== substr($netBin, 0, $whole)) return false;
    if ($remain === 0) return true;
    $mask = (0xFF << (8 - $remain)) & 0xFF;
    return (ord($ipBin[$whole]) & $mask) === (ord($netBin[$whole]) & $mask);
}

function matchesChallengeNetwork(string $ip, string $allowedCidrs): bool {
    foreach (preg_split('/[\s,;]+/', $allowedCidrs, -1, PREG_SPLIT_NO_EMPTY) as $cidr) {
        if (ipInCidr($ip, $cidr)) return true;
    }
    return false;
}

$observedIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
if (!filter_var($observedIp, FILTER_VALIDATE_IP)) {
    challengeReply(200, ['ok' => true, 'challenges' => []]);
}

try {
    $c = getConnection();
    $c->query("SET time_zone='+00:00'");
    $q = $c->query("SELECT challengeId,slug,title,joinText,description,destination,destinationUrl,allowedCidrs,startsAt,endsAt,priority FROM publicChallenge WHERE active=b'1' AND startsAt<=UTC_TIMESTAMP() AND endsAt>=UTC_TIMESTAMP() ORDER BY priority DESC,startsAt ASC,challengeId ASC LIMIT 50");
    $items = [];
    while ($r = $q->fetch_assoc()) {
        if (!matchesChallengeNetwork($observedIp, (string)$r['allowedCidrs'])) continue;
        $items[] = [
            'id' => (int)$r['challengeId'],
            'slug' => (string)$r['slug'],
            'title' => (string)$r['title'],
            'join_text' => (string)$r['joinText'],
            'description' => (string)$r['description'],
            'destination' => (string)$r['destination'],
            'destination_url' => (string)$r['destinationUrl'],
            'starts_at' => (string)$r['startsAt'],
            'ends_at' => (string)$r['endsAt']
        ];
    }
    challengeReply(200, ['ok' => true, 'challenges' => $items]);
} catch (mysqli_sql_exception $e) {
    // A fresh deployment without the optional event table should behave as
    // "no nearby event" rather than breaking the TaraSec app.
    if ((int)$e->getCode() === 1146) challengeReply(200, ['ok' => true, 'challenges' => []]);
    challengeReply(500, ['ok' => false, 'error' => 'challenge_lookup_failed']);
} catch (Throwable $e) {
    challengeReply(500, ['ok' => false, 'error' => 'challenge_lookup_failed']);
}
