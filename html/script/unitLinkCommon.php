<?php
declare(strict_types=1);

class UnitLinkException extends RuntimeException {}

function unitGatewayConfig(): array {
    $path='/etc/tarasec/unit-link.php';
    $cfg=is_readable($path) ? require $path : null;
    if (!is_array($cfg) || !preg_match('/^[a-f0-9]{32}$/D',(string)($cfg['gateway_id'] ?? ''))
        || !preg_match('/^[a-f0-9]{64}$/D',(string)($cfg['subject_key'] ?? '')))
        throw new UnitLinkException('Unit linking is not configured on this gateway.');
    $parts=parse_url((string)($cfg['base_url'] ?? ''));
    if (!$parts || ($parts['scheme'] ?? '')!=='https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['path'] ?? '', ['', '/'],true))
        throw new UnitLinkException('Unit linking requires a configured HTTPS origin.');
    $cfg['base_url']=rtrim($cfg['base_url'],'/');
    return $cfg;
}

function unitLinkConfig(): array {
    $cfg=unitGatewayConfig();
    if (!preg_match('/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/D',(string)($cfg['google_client_id'] ?? '')))
        throw new UnitLinkException('Browser Google linking is not configured. Use the QR linking page instead.');
    return $cfg;
}

function unitSubjectHash(string $subject,array $cfg): string {
    if ($subject==='' || strlen($subject)>255) throw new UnitLinkException('Invalid account identity.');
    return hash_hmac('sha256','google:'.$subject,hex2bin($cfg['subject_key']));
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
    $ch=curl_init('https://tarasec.org/api/v1/identity/unit-identity.php');
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

