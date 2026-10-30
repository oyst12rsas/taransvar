<?php
require_once __DIR__ . '/../html/script/webPokeLib.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
check(!webPokeToken('../escape'), 'Reject traversal');
check(!webPokeToken(str_repeat('a', 64) . "\n"), 'Reject trailing data');
$token = bin2hex(random_bytes(32));
check(webPokeToken($token), 'Accept random session');
$dir = webPokeDirectory();
$path = $dir . '/' . $token . '.json';
try {
    check(webPokeSnapshot($token)['state'] === 'unknown' && !file_exists($path), 'Polling never creates evidence');
    $data = ['senderIp' => WEB_POKE_GATEWAY, 'trafficSeverity' => 0, 'trafficSecondsSince' => 0];
    $clean = webPokeEvidence($data, WEB_POKE_GATEWAY);
    check($clean['state'] === 'clean', 'Real clean tag');
    check(webPokeEvidence(['senderIp'=>WEB_POKE_GATEWAY,'trafficSecondsSince'=>0], WEB_POKE_GATEWAY)['state'] === 'unknown', 'Missing severity cannot become clean');
    webPokeSnapshot($token, $clean);
    // Exercise the actual GET endpoint without mysqli: read-only snapshots must
    // work without loading the traffic/database implementation at all.
    $endpoint = realpath(__DIR__ . '/../html/script/webPokeReceiver.php');
    $script = '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REMOTE_ADDR"]="' . WEB_POKE_GATEWAY . '"; $_GET["token"]="' . $token . '"; include ' . var_export($endpoint, true) . ';';
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($script));
    check((json_decode($output, true)['observation']['state'] ?? '') === 'clean', 'GET endpoint needs no traffic/database code');
    $script = '$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; $_SERVER["HTTP_CLIENT_IP"]="' . WEB_POKE_GATEWAY . '"; $_POST["token"]="' . $token . '"; include ' . var_export($endpoint, true) . ';';
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($script));
    check((json_decode($output, true)['ok'] ?? true) === false, 'Spoofed Client-IP does not authorize Poke');
    $bytes = file_get_contents($path);
    // A gateway change alone cannot alter the frozen receiver observation.
    $data['trafficSeverity'] = 3;
    check(webPokeSnapshot($token)['state'] === 'clean', 'Polling retains clean before Poke');
    check(file_get_contents($path) === $bytes, 'Read-only polling leaves file untouched');
    $infected = webPokeEvidence($data, WEB_POKE_GATEWAY);
    webPokeSnapshot($token, $infected);
    check(webPokeSnapshot($token)['state'] === 'infected', 'Only next Poke records infected');
    foreach ([-1, 3, 100] as $age) {
        $data['trafficSecondsSince'] = $age;
        check(webPokeEvidence($data, WEB_POKE_GATEWAY)['state'] === 'unknown', 'Missing/stale evidence is unknown');
    }
    $data['trafficSecondsSince'] = 0;
    check(webPokeEvidence($data, '100.68.22.33')['state'] === 'unknown', 'Wrong route cannot pass');
    $expired = webPokeSnapshot($token);
    $expired['created'] = time() - WEB_POKE_TTL;
    file_put_contents($path, json_encode($expired));
    check(webPokeSnapshot($token)['state'] === 'unknown', 'Expired session cannot show green');
    check(webPokeSnapshot($token, $clean)['state'] === 'unknown', 'Poke cannot revive expired session');
    check(count(webPokeNodes()) === 4, 'Four receivers');
    check(!array_intersect(['tomato','squash','audi','porsche'], array_keys(webPokeNodes())), 'Exclude other topology');
    echo "Web Poke evidence, polling, route and expiry checks passed.\n";
} finally { @unlink($path); }
