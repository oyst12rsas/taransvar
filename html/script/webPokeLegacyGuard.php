<?php
require_once __DIR__ . '/webPokeGatewayLib.php';
// Cache only policy decisions, never infection evidence. No stale grant is used
// after expiry, and the DB remains authoritative. Bind grants to role/config/peer.
function webPokeLegacyAuthorize(string $identity, callable $authorize, ?int $now = null): void
{
    $now ??= time();
    $key = hash('sha256', $identity);
    try { $cached = webPokeRecord('legacyaccess', $key); }
    catch (DomainException $e) { $cached = []; }
    if ((int)($cached['expires'] ?? 0) > $now) {
        if ($cached['allowed'] ?? false) return;
        throw new DomainException('Demo access denied by the DB server');
    }
    try {
        $authorize();
    } catch (DomainException $e) {
        webPokeRecord('legacyaccess', $key, fn($row) => ['allowed'=>false,'expires'=>$now+10]);
        throw $e;
    }
    // Transport failures are not cached and never extend a previous grant.
    webPokeRecord('legacyaccess', $key, fn($row) => ['allowed'=>true,'expires'=>$now+10]);
}
// Enrolled nodes apply DB gateway policy to older infection/status APIs too.
// This prevents ALLOW_ANY_GATEWAY=NO being bypassed through the v1 or app APIs.
function webPokeLegacyAccess(): void
{
    static $checked = false;
    if ($checked) return;
    $nodePath = '/etc/tarasec-web-poke-node.conf'; $gatewayPath = '/etc/tarasec-web-poke-gateway.conf';
    if (!file_exists($nodePath) && !file_exists($gatewayPath)) return;
    unset($_SERVER['HTTP_CLIENT_IP']);
    try {
        $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $subnet = webPokeLocalSubnet();
        $local = webPokeIsLocal($peer, (string)($subnet['address'] ?? ''), (string)($subnet['mask'] ?? ''));
        if ($local && file_exists($gatewayPath)) {
            $config = webPokeConfig('gateway');
            webPokeLegacyAuthorize(json_encode(['gateway', $config], JSON_THROW_ON_ERROR),
                fn() => webPokeHttp((string)$config['DB_IP'], 'webPokeCoordinator.php', ['action'=>'policy'], 1000));
        } elseif (!$local && file_exists($nodePath) && webPokeIp($peer)) {
            $config = webPokeConfig('node'); $nodeId = (string)$config['NODE_ID'];
            webPokeLegacyAuthorize(json_encode(['node', $config, $peer], JSON_THROW_ON_ERROR), function() use ($config, $nodeId, $peer) {
                $packet = webPokeSeal(['action'=>'access','node_id'=>$nodeId,'gateway_ip'=>$peer], (string)$config['AUTH_KEY']);
                webPokeHttp((string)$config['DB_IP'], 'webPokeCoordinator.php', ['action'=>'receiver','node_id'=>$nodeId]+$packet, 1000);
            });

        } else throw new DomainException('Gateway is not permitted to inspect infection state');
        $checked = true;
    } catch (Throwable $e) { webPokeError($e); }
}
