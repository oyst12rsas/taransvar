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
    if ($action === 'start') {
        $unit = webPokeLocalUnit($peer);
        webPokeRate('gatewaystart', $peer, 5, 60);
        $proof = bin2hex(random_bytes(32));
        webPokeRecord('proof', $proof, fn($row) => $unit + ['client_ip'=>$peer,'device_key'=>hash('sha256', $peer),'origin'=>webPokeOrigin(),'expires'=>time()+60]);
        $central = webPokeHttp(webPokeGatewayDb(), 'webPokeCoordinator.php', ['action'=>'start','proof'=>$proof]);
        if ($central['unit_id'] !== $unit['unit_id'] || $central['owner_id'] !== $unit['owner_id']) throw new DomainException('DB identity mismatch');
        $state = webPokeLocalState($peer);
        if (webPokeNeedsRemediation($state)) {
            webPokeDbCall($central, 'close');
            webPokeReply(409, ['ok'=>false,'remediation_required'=>true,'gateway'=>$state,
                'error'=>'A separate security finding is active for this device. Resolve it on the gateway before joining the demo. Reset never clears security findings.']);
        }
        $token = bin2hex(random_bytes(32));
        $session = $central + $unit + ['client_ip'=>$peer];
        webPokeRecord('gateway', $token, fn($row) => $session);
        $replacement = webPokeDbCall($session, 'replace');
        $cleared = webPokeRestartDevice($session);
        $nodes = [];
        foreach ($central['receivers'] as $id => $node) $nodes[$id] = ['name'=>$node['name'],'address'=>$node['address'],'poke_token'=>$node['poke_token']];
        webPokeReply(200, ['ok'=>true,'token'=>$token,'session_id'=>$central['session_id'],'expires'=>$central['expires'],
            'reset'=>['demo_marks_cleared'=>$cleared,'ssh_sessions_closed'=>$replacement['ssh_sessions_closed'] ?? 0],
            'gateway_ip'=>$central['gateway_ip'],'gateway_name'=>gethostname() ?: 'Gateway','nodes'=>$nodes,'gateway'=>webPokeLocalState($peer)]);
    }
    $session = webPokeGatewaySession((string)($input['token'] ?? ''), $peer);
    // Every request checks current DB policy, including an already-running session.
    $central = webPokeDbCall($session, $action === 'status' ? 'status' : 'authorize');
    $state = webPokeLocalState($peer);
    if (webPokeNeedsRemediation($state)) {
        webPokeDbCall($session, 'close');
        webPokeReply(409, ['ok'=>false,'remediation_required'=>true,'gateway'=>$state,
            'error'=>'A separate security finding is active. Resolve it before continuing the demo.']);
    }
    if ($action === 'mark') webPokeLocalControl($session, true);
    elseif ($action === 'reset') webPokeRestartDevice($session);
    elseif ($action !== 'status') throw new InvalidArgumentException('Unsupported demo action');
    $state = webPokeLocalState($peer);
    webPokeReply(200, ['ok'=>true,'gateway'=>$state,'receivers'=>$central['receivers'],'expires'=>$session['expires'],
        'remediation_required'=>webPokeNeedsRemediation($state)]);
} catch (Throwable $e) { webPokeError($e); }
