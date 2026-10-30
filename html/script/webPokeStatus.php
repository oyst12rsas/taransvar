<?php
require_once __DIR__ . '/../deploymentMode.php';
tarasecRequireDemo();
require_once __DIR__ . '/webPokeLib.php';
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$token = (string)($_GET['token'] ?? '');
if (!webPokeToken($token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid demo session']);
    exit;
}
try {
    $multi = curl_multi_init();
    $handles = [];
    foreach (webPokeNodes() as $id => $node) {
        $ch = curl_init('http://' . $node['address'] . '/script/webPokeReceiver.php?token=' . $token);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 300,
            CURLOPT_TIMEOUT_MS => 800, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP]);
        curl_multi_add_handle($multi, $ch);
        $handles[$id] = $ch;
    }
    do {
        $code = curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 0.05);
    } while ($running && $code === CURLM_OK);
    $results = [];
    foreach ($handles as $id => $ch) {
        $data = json_decode((string)curl_multi_getcontent($ch), true);
        $results[$id] = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && ($data['ok'] ?? false)
            ? $data['observation'] : ['state' => 'unknown', 'message' => 'Receiver unavailable; last Poke could not be checked'];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    echo json_encode(['ok' => true, 'receivers' => $results, 'checked_at' => gmdate('c')]);
} catch (Throwable $e) {
    error_log('webPokeStatus: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Status service unavailable']);
}
