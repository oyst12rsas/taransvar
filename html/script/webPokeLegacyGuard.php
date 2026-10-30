<?php
require_once __DIR__ . '/webPokeGatewayLib.php';
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
            webPokeHttp((string)$config['DB_IP'], 'webPokeCoordinator.php', ['action'=>'policy']);
        } elseif (!$local && file_exists($nodePath) && webPokeIp($peer)) {
            $config = webPokeConfig('node'); $nodeId = (string)$config['NODE_ID'];
            $packet = webPokeSeal(['action'=>'access','node_id'=>$nodeId,'gateway_ip'=>$peer], (string)$config['AUTH_KEY']);
            webPokeHttp((string)$config['DB_IP'], 'webPokeCoordinator.php', ['action'=>'receiver','node_id'=>$nodeId]+$packet);

        } else throw new DomainException('Gateway is not permitted to inspect infection state');
        $checked = true;
    } catch (Throwable $e) { webPokeError($e); }
}
