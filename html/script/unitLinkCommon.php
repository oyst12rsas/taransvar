<?php
declare(strict_types=1);

require_once __DIR__.'/serviceDiscoveryCommon.php';

class UnitLinkException extends RuntimeException {}

function unitLinkConfig(): array {
    $path='/etc/tarasec/unit-link.php';
    $cfg=is_readable($path) ? require $path : null;
    return unitLinkValidateConfig($cfg);
}

function unitLinkValidateConfig($cfg): array {
    if (!is_array($cfg) || !preg_match('/^[a-f0-9]{32}$/D',(string)($cfg['gateway_id'] ?? ''))
        || !preg_match('/^[a-f0-9]{64}$/D',(string)($cfg['subject_key'] ?? '')))
        throw new UnitLinkException('Unit linking is not configured on this gateway.');
    $cfg['mode']=$cfg['mode'] ?? 'google_https';
    if (!in_array($cfg['mode'],['google_https','service_handoff','hosted_gateway'],true)) throw new UnitLinkException('Unsupported linking configuration.');
    $parts=parse_url((string)($cfg['base_url'] ?? ''));
    $vpn=$cfg['mode']==='service_handoff' && ($cfg['transport'] ?? '')==='netbird';
    if (!$parts || (!($parts['scheme']==='https') && !(($cfg['mode']==='hosted_gateway' || ($vpn && unitNetBirdIp($parts['host'] ?? ''))) && $parts['scheme']==='http') ) || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['path'] ?? '', ['', '/'],true))
        throw new UnitLinkException('Unit linking requires HTTPS or an explicitly configured NetBird origin.');
    if ($cfg['mode']==='google_https' && !preg_match('/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/D',(string)($cfg['google_client_id'] ?? '')))
        throw new UnitLinkException('Configure the gateway Google web client.');
    if ($vpn && !preg_match('/^[A-Za-z0-9_.-]{1,15}$/D',(string)($cfg['netbird_interface'] ?? '')))
        throw new UnitLinkException('Configure the encrypted NetBird interface.');
    $cfg['base_url']=rtrim($cfg['base_url'],'/');
    return $cfg;
}

function unitNetBirdIp(string $ip): bool {
    return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false && str_starts_with($ip,'100.68.');
}

function unitLinkTransport(array $cfg,?string $guard=null): void {
    if (in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')),['on','1'],true)) return;
    $u=parse_url($cfg['base_url']);
    if ($cfg['mode']!=='service_handoff' || ($cfg['transport'] ?? '')!=='netbird' || ($u['scheme'] ?? '')!=='http'
        || !unitNetBirdIp(unitLocalPeer()) || ($_SERVER['SERVER_ADDR'] ?? '')!==($u['host'] ?? '')
        || (int)($_SERVER['SERVER_PORT'] ?? 0)!==(int)($u['port'] ?? 80))
        throw new UnitLinkException('Use HTTPS or the configured encrypted NetBird connection.');
    $expected=$cfg['gateway_id'].'|'.$cfg['netbird_interface'].'|'.$cfg['base_url'];
    if ($guard===null && (!is_readable('/run/tarasec-unit-link/transport') || filemtime('/run/tarasec-unit-link/transport')<time()-45))
        throw new UnitLinkException('The NetBird transport guard needs to be running.');
    $guard ??= is_readable('/run/tarasec-unit-link/transport') ? trim((string)file_get_contents('/run/tarasec-unit-link/transport')) : '';
    if (!hash_equals($expected,$guard)) throw new UnitLinkException('The NetBird linking transport guard is not active.');
}

function unitSubjectHash(string $subject,array $cfg): string {
    if ($subject==='' || strlen($subject)>255) throw new UnitLinkException('Invalid account identity.');
    $scope=in_array($cfg['mode'] ?? '',['service_handoff','hosted_gateway'],true) ? taraAccountServices()['identity_api_base'].'|' : '';
    return hash_hmac('sha256',$scope.'google:'.$subject,hex2bin($cfg['subject_key']));
}

function unitGoogleClaimsSubject(array $claims,array $cfg,string $nonce): string {
    if ($nonce==='' || !in_array($claims['iss'] ?? '',['accounts.google.com','https://accounts.google.com'],true)
        || ($claims['aud'] ?? '')!==$cfg['google_client_id'] || (int)($claims['exp'] ?? 0)<=time()
        || !hash_equals($nonce,(string)($claims['nonce'] ?? '')) || empty($claims['sub'])
        || !is_string($claims['sub']) || strlen($claims['sub'])>255)
        throw new UnitLinkException('Google sign-in could not be verified.');
    return $claims['sub'];
}

function unitLocalPeer(): string {
    $peer=(string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (str_starts_with($peer,'::ffff:')) $peer=substr($peer,7);
    if (!filter_var($peer,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) throw new UnitLinkException('An identifiable local IPv4 connection is required.');
    return $peer; // Never trust a caller-supplied forwarded/client IP.
}

function unitAtLocalPeer(mysqli $db,string $peer): array {
    $setup=$db->query('SELECT adminIP,nettmask FROM setup LIMIT 1')->fetch_assoc();
    $peerLong=unpack('N',inet_pton($peer))[1];
    $mask=(int)($setup['nettmask'] ?? 0); $admin=(int)($setup['adminIP'] ?? 0);
    if ($mask===0 || (($peerLong & $mask)!==($admin & $mask))) throw new UnitLinkException('Open this page from the unit on its own gateway LAN.');
    // Require current canonical attribution. Do not claim an old DHCP lease.
    $stmt=$db->prepare("SELECT unitId,ownerId,COALESCE(hostname,'') hostname FROM unit WHERE ipAddress=INET_ATON(?) AND lastSeen>NOW()-INTERVAL 15 MINUTE ORDER BY lastSeen DESC LIMIT 2");
    $stmt->bind_param('s',$peer); $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    if (count($rows)!==1) throw new UnitLinkException('This connection has no unambiguous recent unit identity. Ask the gateway operator to check attribution.');
    return $rows[0];
}

function unitLinkHttps(): void {
    // Set HTTPS at the web-server boundary. Forwarded headers are not proof.
    if (strtolower((string)($_SERVER['HTTPS'] ?? ''))!=='on' && (string)($_SERVER['HTTPS'] ?? '')!=='1')
        throw new UnitLinkException('Use the gateway HTTPS address to protect linking credentials.');
}

function unitRedeemIdentity(string $ticket,array $cfg): string {
    if (!preg_match('/^[a-f0-9]{64}$/D',$ticket)) throw new UnitLinkException('Invalid identity handoff.');
    $services=taraAccountServices();
    $ch=curl_init($services['identity_api_base'].'/unit-identity.php');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query([
        'action'=>'redeem','gateway_id'=>$cfg['gateway_id'],'ticket'=>$ticket
    ]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12]);
    $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    $json=is_string($body)?json_decode($body,true):null;
    if ($status!==200 || !is_array($json) || ($json['ok'] ?? false)!==true
        || ($json['gateway_id'] ?? '')!==$cfg['gateway_id'] || empty($json['subject']))
        throw new UnitLinkException('Identity handoff expired or could not be verified. Sign in again and retry.');
    return unitSubjectHash((string)$json['subject'],$cfg);
}

function unitServiceNodeSecret(array $cfg): string {
    return hash_hmac('sha256','hosted-app-node:'.taraAccountServices()['identity_api_base'].':'.$cfg['gateway_id'],hex2bin($cfg['subject_key']));
}
function unitServiceNodeId(array $cfg): string { return substr(hash('sha256',unitServiceNodeSecret($cfg)),0,32); }
