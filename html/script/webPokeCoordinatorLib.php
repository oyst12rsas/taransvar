<?php
require_once __DIR__ . '/webPokeLib.php';
function webPokeCoordinator(array $config, string $peer, array $input, callable $rpc, callable $registered): array
{
    $action = (string)($input['action'] ?? '');
    if ($action === 'receiver') {
        $nodeId = (string)($input['node_id'] ?? ''); $nodes = webPokeNodes();
        if (!isset($nodes[$nodeId]) || $nodes[$nodeId]['address'] !== $peer) throw new DomainException('Receiver authentication required');
        $message = webPokeVerify($input, (string)($config['KEY_' . strtoupper($nodeId)] ?? ''));
        if (($message['node_id'] ?? '') !== $nodeId) throw new DomainException('Receiver mismatch');
        if (($message['action'] ?? '') === 'access') {
            $gateway = (string)($message['gateway_ip'] ?? '');
            webPokeRequireAllowed($config, $gateway);
            if (!$registered($gateway)) throw new DomainException('Registered gateway required');
            return ['ok'=>true];
        }
        $id = (string)($message['session_id'] ?? '');
        $session = webPokeRecord('central', $id); webPokeActive($session);
        webPokeRequireAllowed($config, $session['gateway_ip']);
        if (!$registered($session['gateway_ip'])) throw new DomainException('Registered gateway required');
        if (!isset($session['receivers'][$nodeId])) throw new DomainException('Receiver not in session');
        if (($message['action'] ?? '') === 'validate') return ['ok'=>true];
        if (($message['action'] ?? '') !== 'report') throw new InvalidArgumentException('Unsupported receiver message');
        $observation = $message['observation'] ?? [];
        if (!in_array($observation['state'] ?? '', ['clean','infected','unknown'], true)) throw new InvalidArgumentException('Invalid observation');
        webPokeRecord('central', $id, function ($row) use ($nodeId, $observation) {
            webPokeActive($row); $row['receivers'][$nodeId]['observation'] = $observation; return $row;
        });
        return ['ok'=>true];
    }
    // Authorization precedes identity callbacks, session lookups and state disclosure.
    webPokeRequireAllowed($config, $peer);
    if (!$registered($peer)) throw new DomainException('Registered gateway required');
    if ($action === 'policy') return ['ok'=>true];
    if ($action === 'start') {
        webPokeRate('centralstart', $peer, 20, 60);
        $proof = (string)($input['proof'] ?? '');
        if (!webPokeToken($proof)) throw new DomainException('Gateway identity proof required');
        $identity = $rpc($peer, 'webPokeGateway.php', ['action'=>'resolve','proof'=>$proof]);
        if ((int)($identity['owner_id'] ?? 0) < 1 || (int)($identity['unit_id'] ?? 0) < 1
            || !preg_match('~\Ahttps?://(?:\d{1,3}\.){3}\d{1,3}(?::\d{1,5})?\z~D', (string)($identity['origin'] ?? '')))
            throw new DomainException('Gateway must identify the local owner and unit');
        $id = bin2hex(random_bytes(32)); $control = bin2hex(random_bytes(32));
        $session = ['expires'=>time()+WEB_POKE_TTL, 'gateway_ip'=>$peer, 'owner_id'=>(int)$identity['owner_id'],
            'unit_id'=>(int)$identity['unit_id'], 'control_hash'=>hash('sha256', $control), 'receivers'=>[]];
        foreach (webPokeNodes() as $nodeId => $node) {
            $key = (string)($config['KEY_' . strtoupper($nodeId)] ?? '');
            $poke = bin2hex(random_bytes(32));
            $registration = ['action'=>'register','node_id'=>$nodeId,'session_id'=>$id,'poke_hash'=>hash('sha256', $poke),
                'gateway_ip'=>$peer,'origin'=>$identity['origin'],'expires'=>$session['expires']];
            // Admission succeeds only if every configured receiver acknowledges DB authorization.
            $rpc($node['address'], 'webPokeReceiver.php', webPokeSeal($registration, $key));
            $session['receivers'][$nodeId] = $node + ['poke_token'=>$poke, 'observation'=>['state'=>'unknown','message'=>'Not yet poked']];
        }
        webPokeRecord('central', $id, fn($row) => $session);
        return ['ok'=>true,'session_id'=>$id,'control_token'=>$control,'expires'=>$session['expires'],
            'owner_id'=>$session['owner_id'],'unit_id'=>$session['unit_id'],'gateway_ip'=>$peer,'receivers'=>$session['receivers']];
    }
    $id = (string)($input['session_id'] ?? ''); $control = (string)($input['control_token'] ?? '');
    $session = webPokeRecord('central', $id); webPokeActive($session);
    if (!webPokeToken($control) || !hash_equals($session['control_hash'], hash('sha256', $control)) || $session['gateway_ip'] !== $peer)
        throw new DomainException('Gateway session authorization required');
    if (!in_array($action, ['status','authorize','close'], true)) throw new InvalidArgumentException('Unsupported demo action');
    if ($action === 'close') webPokeRecord('central', $id, function($row) { $row['revoked']=true; return $row; });
    $observations = [];
    foreach ($session['receivers'] as $nodeId => $node) $observations[$nodeId] = $node['observation'];
    return ['ok'=>true,'receivers'=>$observations,'expires'=>$session['expires']];
}
