<?php
require_once __DIR__ . '/../deploymentMode.php';
tarasecRequireDemo();
require_once __DIR__ . '/webPokeLib.php';
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: http://' . WEB_POKE_GATEWAY);
header('Vary: Origin');
header('Referrer-Policy: no-referrer');
header('Connection: close');
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $token = (string)($method === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));
    if (!webPokeToken($token)) throw new InvalidArgumentException('Invalid demo session');
    if ($method === 'GET') {
        // Only gateway-to-receiver polling. Never read current traffic here.
        if (($_SERVER['REMOTE_ADDR'] ?? '') !== WEB_POKE_GATEWAY) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Status must be requested through Standard gateway']);
            exit;
        }
        echo json_encode(['ok' => true, 'observation' => webPokeSnapshot($token)]);
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['ok' => false, 'error' => 'GET or POST required']);
        exit;
    }
    if (($_SERVER['REMOTE_ADDR'] ?? '') !== WEB_POKE_GATEWAY
        || ($_SERVER['HTTP_ORIGIN'] ?? '') !== 'http://' . WEB_POKE_GATEWAY) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Open this demo on Standard gateway and connect through it']);
        exit;
    }
    // The real socket peer is authoritative; the legacy helper accepts Client-IP.
    unset($_SERVER['HTTP_CLIENT_IP']);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    chdir(__DIR__);
    require_once __DIR__ . '/../dbfunc.php';
    require_once __DIR__ . '/../taraLib.php';
    $deadline = microtime(true) + 1.5;
    do {
        $data = getTagData();
        if ((int)($data['trafficSecondsSince'] ?? -1) === 0) break;
        usleep(100000);
    } while (microtime(true) < $deadline);
    $observation = webPokeEvidence($data, $_SERVER['REMOTE_ADDR']);
    echo json_encode(['ok' => true, 'observation' => webPokeSnapshot($token, $observation)]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('webPokeReceiver: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Receiver observation unavailable']);
}
