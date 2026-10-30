<?php
require_once __DIR__ . '/../deploymentMode.php'; tarasecRequireDemo();
require_once __DIR__ . '/webPokeLib.php';
ini_set('display_errors', '0');
header('Connection: close'); header('Referrer-Policy: no-referrer');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new InvalidArgumentException('POST required');
    $config = webPokeConfig('node'); $nodeId = (string)($config['NODE_ID'] ?? '');
    $key = (string)($config['AUTH_KEY'] ?? ''); $dbIp = (string)($config['DB_IP'] ?? '');
    $peer = (string)($_SERVER['REMOTE_ADDR'] ?? ''); $input = webPokeInput();
    if (isset($input['signature'])) {
        if ($peer !== $dbIp) throw new DomainException('DB registration required');
        $message = webPokeVerify($input, $key);
        if (($message['action'] ?? '') !== 'register' || ($message['node_id'] ?? '') !== $nodeId
            || !webPokeIp((string)($message['gateway_ip'] ?? '')) || (int)($message['expires'] ?? 0) > time()+WEB_POKE_TTL
            || !webPokeToken((string)($message['poke_hash'] ?? ''))) throw new DomainException('Invalid DB registration');
        webPokeActive($message);
        webPokeRecord('receiver', (string)($message['session_id'] ?? ''), fn($row) => $message);
        webPokeReply(200, ['ok'=>true]);
    }
    $id = (string)($input['session_id'] ?? ''); $poke = (string)($input['poke_token'] ?? '');
    $session = webPokeRecord('receiver', $id); webPokeActive($session);
    if (!webPokeToken($poke) || !hash_equals($session['poke_hash'], hash('sha256', $poke))
        || $peer !== $session['gateway_ip'] || ($_SERVER['HTTP_ORIGIN'] ?? '') !== $session['origin'])
        throw new DomainException('Authorized gateway session required');
    require_once __DIR__ . '/webPokeGatewayLib.php';
    require_once __DIR__ . '/../dbfunc.php';
    $subnet = webPokeLocalSubnet();
    if (webPokeIsLocal($peer, (string)($subnet['address'] ?? ''), (string)($subnet['mask'] ?? '')))
        throw new DomainException('Local units must use this node as their gateway');
    header('Access-Control-Allow-Origin: ' . $session['origin']); header('Vary: Origin');
    webPokeRate('poke', $id, 30, 60);
    $callDb = function(string $action, array $extra=[]) use ($dbIp,$key,$nodeId,$id): array {
        $packet = webPokeSeal(['action'=>$action,'node_id'=>$nodeId,'session_id'=>$id]+$extra, $key);
        return webPokeHttp($dbIp, 'webPokeCoordinator.php', ['action'=>'receiver','node_id'=>$nodeId]+$packet);
    };
    // Fail closed on policy changes or DB loss, before reading any traffic state.
    $callDb('validate');
    unset($_SERVER['HTTP_CLIENT_IP']);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    chdir(__DIR__); require_once __DIR__ . '/../dbfunc.php'; require_once __DIR__ . '/../taraLib.php';
    $deadline = microtime(true)+1.5;
    do {
        $data=getTagData();
        if ((int)($data['trafficSecondsSince'] ?? -1)===0) break;
        usleep(100000);
    } while (microtime(true)<$deadline);
    $observation = webPokeEvidence($data, $peer, $session['gateway_ip']);
    $callDb('report', ['observation'=>$observation]);
    webPokeReply(200, ['ok'=>true,'observation'=>$observation]);
} catch (Throwable $e) { webPokeError($e); }
