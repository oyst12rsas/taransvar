<?php
require_once __DIR__ . '/../deploymentMode.php'; tarasecRequireDemo();
require_once __DIR__ . '/webPokeGatewayLib.php';
ini_set('display_errors', '0');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new InvalidArgumentException('POST required');
    $input = webPokeInput(); $action = (string)($input['action'] ?? ''); $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($action === 'resolve') {
        if ($peer !== webPokeGatewayDb()) throw new DomainException('Configured DB server required');
        $proof = (string)($input['proof'] ?? '');
        $identity = webPokeRecord('proof', $proof, function($row) {
            webPokeActive($row);
            if ($row['used'] ?? false) throw new DomainException('Identity proof already redeemed');
            $row['used']=true; return $row;
        });
        unset($identity['client_ip']);
        webPokeReply(200, ['ok'=>true]+$identity);
    }
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== webPokeOrigin()) throw new DomainException('Use this gateway’s demo page');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . '/../dbfunc.php';
    $unit = webPokeLocalUnit($peer);
    if ($action === 'start') {
        webPokeRate('gatewaystart', $peer, 5, 60);
        // Close/reset the previous session only with its own device-bound token.
        if (!empty($input['token'])) {
            $previous = webPokeGatewaySession((string)$input['token'], $peer);
            webPokeDbCall($previous, 'authorize'); webPokeLocalControl($previous, false);
            webPokeDbCall($previous, 'close');
        }
        $proof = bin2hex(random_bytes(32));
        webPokeRecord('proof', $proof, fn($row) => $unit + ['client_ip'=>$peer,'origin'=>webPokeOrigin(),'expires'=>time()+60]);
        $central = webPokeHttp(webPokeGatewayDb(), 'webPokeCoordinator.php', ['action'=>'start','proof'=>$proof]);
        if ($central['unit_id'] !== $unit['unit_id'] || $central['owner_id'] !== $unit['owner_id']) throw new DomainException('DB identity mismatch');
        $token = bin2hex(random_bytes(32));
        $session = $central + $unit + ['client_ip'=>$peer];
        webPokeRecord('gateway', $token, fn($row) => $session);
        $nodes = [];
        foreach ($central['receivers'] as $id => $node) $nodes[$id] = ['name'=>$node['name'],'address'=>$node['address'],'poke_token'=>$node['poke_token']];
        webPokeReply(200, ['ok'=>true,'token'=>$token,'session_id'=>$central['session_id'],'expires'=>$central['expires'],
            'gateway_ip'=>$central['gateway_ip'],'gateway_name'=>gethostname() ?: 'Gateway','nodes'=>$nodes,'gateway'=>webPokeLocalState($peer)]);
    }
    $session = webPokeGatewaySession((string)($input['token'] ?? ''), $peer);
    // Every request checks current DB policy, including an already-running session.
    $central = webPokeDbCall($session, $action === 'status' ? 'status' : 'authorize');
    if ($action === 'mark') webPokeLocalControl($session, true);
    elseif ($action === 'reset') webPokeLocalControl($session, false);
    elseif ($action !== 'status') throw new InvalidArgumentException('Unsupported demo action');
    webPokeReply(200, ['ok'=>true,'gateway'=>webPokeLocalState($peer),'receivers'=>$central['receivers'],'expires'=>$session['expires']]);
} catch (Throwable $e) { webPokeError($e); }
