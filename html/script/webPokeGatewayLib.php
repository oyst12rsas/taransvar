<?php
require_once __DIR__ . '/webPokeLib.php';
function webPokeGatewayDb(): string
{
    $config = webPokeConfig('gateway');
    $ip = (string)($config['DB_IP'] ?? '');
    if (!webPokeIp($ip)) throw new RuntimeException('Configure the demo DB NetBird address');
    return $ip;
}
function webPokeLocalSubnet(): array
{
    $db = getConnection();
    try {
        $result = $db->query('SELECT INET_NTOA(internalIP) address,INET_NTOA(nettmask) mask FROM setup LIMIT 1');
        return $result->fetch_assoc() ?: [];
    } finally { $db->close(); }
}
function webPokeRequireLocal(string $peer): void
{
    $subnet = webPokeLocalSubnet();
    if (!webPokeIsLocal($peer, (string)($subnet['address'] ?? ''), (string)($subnet['mask'] ?? '')))
        throw new DomainException('This unit is outside the local subnet; use its own gateway to start the demo');
}
function webPokeLocalUnit(string $peer): array
{
    webPokeRequireLocal($peer);
    if (!filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new DomainException('IPv4 local unit required');
    $db = getConnection();
    $stmt = $db->prepare('SELECT unitId,ownerId FROM unit WHERE ipAddress=INET_ATON(?) ORDER BY lastSeen DESC,unitId DESC LIMIT 1');
    $stmt->bind_param('s', $peer); $stmt->execute(); $unit = $stmt->get_result()->fetch_assoc(); $stmt->close(); $db->close();
    // Registration is optional for demo visitors. Zero means unknown, never a new owner/unit.
    // The browser capability, actual socket peer and local subnet still bind the session.
    return ['owner_id'=>max(0, (int)($unit['ownerId'] ?? 0)),
        'unit_id'=>max(0, (int)($unit['unitId'] ?? 0))];
}
function webPokeLocalState(string $peer): array
{
    $db = getConnection();
    $stmt = $db->prepare("SELECT why FROM internalInfections WHERE ip=INET_ATON(?) AND active=b'1' AND severity>1");
    $stmt->bind_param('s', $peer); $stmt->execute(); $result = $stmt->get_result();
    $sources = ['web_poke'=>0,'other_demo'=>0,'security_finding'=>0];
    while ($row = $result->fetch_assoc()) {
        $why = (string)($row['why'] ?? '');
        $source = str_starts_with($why, 'DEMO:WEB_POKE:') ? 'web_poke'
            : (str_starts_with($why, 'DEMO:') ? 'other_demo' : 'security_finding');
        ++$sources[$source];
    }
    $stmt->close(); $db->close();
    $infected = array_sum($sources) > 0;
    $reasons = [];
    if ($sources['web_poke']) $reasons[] = 'this web demo';
    if ($sources['other_demo']) $reasons[] = 'another demo';
    if ($sources['security_finding']) $reasons[] = 'a separate security finding';
    return ['state'=>$infected ? 'infected':'clean','observed_at'=>gmdate('c'),'sources'=>$sources,
        'message'=>$infected ? 'This device is marked infected by ' . implode(', ', $reasons)
            : 'This device is currently clean on this gateway'];
}
function webPokeNeedsRemediation(array $state): bool
{
    return (int)($state['sources']['security_finding'] ?? 0) > 0;
}
function webPokeLocalControl(array $session, bool $infected): void
{
    $db = getConnection(); $ip = $session['client_ip']; $unit = $session['unit_id'];
    $why = 'DEMO:WEB_POKE:' . $session['session_id'];
    // One row owned by this exact session. Never overwrite or clear other evidence.
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT infectionId FROM internalInfections WHERE ip=INET_ATON(?) AND unitId=? AND why=? FOR UPDATE');
        $stmt->bind_param('sis', $ip, $unit, $why); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($row) {
            $active = $infected ? 1 : 0;
            $stmt=$db->prepare("UPDATE internalInfections SET active=?,handled=b'0',lastSeen=NOW(),severity=3 WHERE infectionId=?");
            $stmt->bind_param('ii', $active, $row['infectionId']); $stmt->execute(); $stmt->close();
        } elseif ($infected) {
            $stmt=$db->prepare("INSERT INTO internalInfections(ip,nettmask,unitId,status,handled,active,lastSeen,severity,why) VALUES(INET_ATON(?),4294967295,?,'unknown',b'0',b'1',NOW(),3,?)");
            $stmt->bind_param('sis', $ip, $unit, $why); $stmt->execute(); $stmt->close();
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
    finally { $db->close(); }
}
function webPokeGatewaySession(string $token, string $peer): array
{
    $row = webPokeRecord('gateway', $token); webPokeActive($row);
    webPokeRequireLocal($peer);
    if ($row['client_ip'] !== $peer)
        throw new DomainException('This demo session belongs to another unit');
    return $row;
}
function webPokeRestartDevice(array $session): void
{
    // Called only after a new DB-authorized start. Recover this device's web-demo
    // marks even after reload/expiry, without clearing other demos or findings.
    $db = getConnection();
    try {
        $stmt = $db->prepare("UPDATE internalInfections SET active=b'0',handled=b'0',lastSeen=NOW() WHERE ip=INET_ATON(?) AND (unitId=? OR unitId=0 OR unitId IS NULL) AND LEFT(why,14)='DEMO:WEB_POKE:' AND active=b'1'");
        $stmt->bind_param('si', $session['client_ip'], $session['unit_id']);
        $stmt->execute(); $stmt->close();
    } finally { $db->close(); }
}
function webPokeDbCall(array $session, string $action): array
{
    return webPokeHttp(webPokeGatewayDb(), 'webPokeCoordinator.php', ['action'=>$action,
        'session_id'=>$session['session_id'],'control_token'=>$session['control_token']]);
}
function webPokeOrigin(): string
{
    $ip = (string)($_SERVER['SERVER_ADDR'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new DomainException('Open the demo using this gateway’s IPv4 address');
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $port = (int)($_SERVER['SERVER_PORT'] ?? ($secure ? 443 : 80));
    return ($secure ? 'https://' : 'http://') . $ip . (($secure && $port===443)||(!$secure && $port===80) ? '' : ':' . $port);
}
