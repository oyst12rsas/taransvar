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
echo "Unit link identity and transport boundary tests passed\n";
