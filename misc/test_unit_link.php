<?php
declare(strict_types=1);
// Run with php -n misc/test_unit_link.php: no live gateway, Google or DB needed.
require_once __DIR__.'/../html/script/unitLinkCommon.php';
function check(bool $condition,string $message): void { if (!$condition) throw new Exception($message); }
function rejects(callable $f,string $message): void { try { $f(); } catch (UnitLinkException $e) { return; } throw new Exception($message); }
$cfg=['google_client_id'=>'test.apps.googleusercontent.com','subject_key'=>str_repeat('a',64)];
$claims=['iss'=>'https://accounts.google.com','aud'=>$cfg['google_client_id'],'exp'=>time()+300,'nonce'=>'session-nonce','sub'=>'google-account-1'];
check(unitGoogleClaimsSubject($claims,$cfg,'session-nonce')==='google-account-1','Valid signed claims should retain stable subject');
foreach (['iss'=>'https://attacker.example','aud'=>'other.apps.googleusercontent.com','exp'=>time()-1,'nonce'=>'different-session','sub'=>''] as $field=>$value) {
    $bad=$claims; $bad[$field]=$value;
    rejects(fn()=>unitGoogleClaimsSubject($bad,$cfg,'session-nonce'),'Reject mismatched '.$field);
}
rejects(fn()=>unitGoogleClaimsSubject($claims,$cfg,''),'Missing session must fail');
$h=unitSubjectHash('google-account-1',$cfg);
check($h!==unitSubjectHash('google-account-2',$cfg),'Different accounts must not share a local hash');
$other=$cfg; $other['subject_key']=str_repeat('b',64);
check($h!==unitSubjectHash('google-account-1',$other),'Different gateway keys must isolate account hashes');
rejects(fn()=>unitSubjectHash('',$cfg),'Empty subject must fail');
$_SERVER=['HTTPS'=>'off','HTTP_X_FORWARDED_PROTO'=>'https'];
rejects(fn()=>unitLinkHttps(),'Caller forwarded HTTPS must not authorize cleartext');
$_SERVER['HTTPS']='on'; unitLinkHttps();
$_SERVER=['REMOTE_ADDR'=>'::ffff:192.168.50.3','HTTP_X_FORWARDED_FOR'=>'192.168.50.99'];
check(unitLocalPeer()==='192.168.50.3','Unit attribution must use direct peer');
$_SERVER['REMOTE_ADDR']='not-an-ip'; rejects(fn()=>unitLocalPeer(),'Invalid direct peer must fail');
rejects(fn()=>unitRedeemIdentity('invalid',$cfg),'Invalid handoff must fail before HTTP');

// Minimal DB fixture for the local-attribution access boundary. No production
// SQL or live credentials run in these tests; rows represent recent candidates.
class UnitResult {
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array { return $this->rows[0] ?? null; }
    public function fetch_all(int $mode): array { return $this->rows; }
}
class UnitStatement {
    public function __construct(private array $rows) {}
    public function bind_param(string $types,string &$peer): void {}
    public function execute(): void {}
    public function get_result(): UnitResult { return new UnitResult($this->rows); }
    public function close(): void {}
}
class mysqli {
    public function __construct(public array $units) {}
    public function query(string $sql): UnitResult { return new UnitResult([['adminIP'=>ip2long('192.168.50.1'),'nettmask'=>ip2long('255.255.255.0')]]); }
    public function prepare(string $sql): UnitStatement { return new UnitStatement($this->units); }
}
define('MYSQLI_ASSOC',1);
$row=['unitId'=>7,'ownerId'=>1,'hostname'=>'Laptop'];
check(unitAtLocalPeer(new mysqli([$row]),'192.168.50.3')['unitId']===7,'One current local candidate should resolve');
rejects(fn()=>unitAtLocalPeer(new mysqli([$row]),'192.168.60.3'),'A different LAN must not authorize linking');
rejects(fn()=>unitAtLocalPeer(new mysqli([]),'192.168.50.3'),'Missing or stale attribution must fail');
rejects(fn()=>unitAtLocalPeer(new mysqli([$row,$row]),'192.168.50.3'),'Ambiguous attribution must fail');
echo "Unit link identity and transport boundary tests passed\n";
