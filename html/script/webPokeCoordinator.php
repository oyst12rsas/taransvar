<?php
require_once __DIR__ . '/../deploymentMode.php'; tarasecRequireDemo();
require_once __DIR__ . '/webPokeCoordinatorLib.php';
ini_set('display_errors', '0');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new InvalidArgumentException('POST required');
    $config = webPokeConfig('db');
    require_once __DIR__ . '/../dbfunc.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $registered = function (string $ip): bool {
        $db = getConnection();
        $stmt = $db->prepare('SELECT 1 FROM partnerRouter WHERE ip=INET_ATON(?) LIMIT 1');
        $stmt->bind_param('s', $ip); $stmt->execute(); $found = (bool)$stmt->get_result()->fetch_row();
        $stmt->close(); $db->close(); return $found;
    };
    webPokeReply(200, webPokeCoordinator($config, (string)($_SERVER['REMOTE_ADDR'] ?? ''), webPokeInput(), 'webPokeHttp', $registered));
} catch (Throwable $e) { webPokeError($e); }
