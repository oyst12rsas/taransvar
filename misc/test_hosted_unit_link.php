<?php
declare(strict_types=1);
require_once __DIR__.'/../html/script/unitLinkRequestCommon.php';
function expect(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
function denied(callable $f): void { try { $f(); } catch (UnitLinkException $e) { return; } throw new RuntimeException('Expected rejection'); }
$cfg=['mode'=>'service_handoff','gateway_id'=>str_repeat('a',32),'subject_key'=>str_repeat('b',64),
    'base_url'=>'http://100.68.1.2','transport'=>'netbird','netbird_interface'=>'wt0'];
unitLinkValidateConfig($cfg);
$_SERVER=['REMOTE_ADDR'=>'100.68.1.3','SERVER_ADDR'=>'100.68.1.2','SERVER_PORT'=>'80'];
$guard=$cfg['gateway_id'].'|wt0|'.$cfg['base_url']; unitLinkTransport($cfg,$guard);
denied(fn()=>unitLinkTransport($cfg,''));
foreach (['REMOTE_ADDR'=>'192.168.1.3','SERVER_ADDR'=>'192.168.1.2','SERVER_PORT'=>'8080'] as $field=>$value) {
    $before=$_SERVER[$field]; $_SERVER[$field]=$value; denied(fn()=>unitLinkTransport($cfg,$guard)); $_SERVER[$field]=$before;
}
$bad=$cfg; $bad['mode']='google_https'; denied(fn()=>unitLinkTransport($bad,$guard));
$bad=$cfg; $bad['base_url']='http://attacker.example'; denied(fn()=>unitLinkValidateConfig($bad));
$provider='https://tarasec.org/api/v1/identity'; $id=str_repeat('c',32);
$url=$provider.'/unit-approve.php?request='.$id.'#key='.str_repeat('d',64);
expect(unitApprovalUrl($url,$provider,$id)===$url,'Expected selected-provider URL');
foreach ([str_replace('tarasec.org','attacker.example',$url),str_replace('/unit-approve.php','/other.php',$url),str_replace('request='.$id,'request='.str_repeat('e',32),$url),str_replace('https://','http://',$url)] as $bad) denied(fn()=>unitApprovalUrl($bad,$provider,$id));
expect(unitPollToken($id,$cfg)!==unitPollToken(str_repeat('d',32),$cfg),'Requests must isolate poll tokens');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','test','hosted_link_test',3306);
$db->query('CREATE TABLE unit (unitId INT PRIMARY KEY,ownerId INT NULL,ipAddress INT UNSIGNED,lastSeen DATETIME) ENGINE=InnoDB');
$db->multi_query(file_get_contents(__DIR__.'/unit_google_link.sql'));
do { if ($r=$db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
$db->query(file_get_contents(__DIR__.'/unit_link_request.sql'));
$db->query("INSERT INTO unit VALUES(7,1,INET_ATON('100.68.1.3'),NOW())");
$q=unitLinkQuery($db,'INSERT INTO unitLinkRequest(requestId,unitId,ownerBinding,peer,gatewayId,provider,expiresAt) VALUES(?,7,1,?,?,?,NOW()+INTERVAL 10 MINUTE)',[$id,'100.68.1.3',$cfg['gateway_id'],$provider]); $q->close();
$r=$db->query('SELECT * FROM unitLinkRequest')->fetch_assoc();
expect(unitApplyApproval($db,$r,$cfg,'subject-1'),'First approval must apply');
$db->query("UPDATE unitGoogleLink SET active=b'0'");
expect(!unitApplyApproval($db,$r,$cfg,'subject-1'),'ACK retry must not apply twice');
expect((int)$db->query('SELECT CAST(active AS UNSIGNED) FROM unitGoogleLink')->fetch_row()[0]===0,'Revocation must survive retries');
$db->query("UPDATE unitLinkRequest SET state='pending'");
$db->query('UPDATE unit SET ownerId=2');
expect(!unitApplyApproval($db,$r,$cfg,'subject-1'),'Changed owner must invalidate request');
expect($db->query('SELECT state FROM unitLinkRequest')->fetch_row()[0]==='cancelled','Binding change must cancel');
$db->query('UPDATE unit SET ownerId=1');
$db->query("UPDATE unitLinkRequest SET state='pending',expiresAt=NOW()-INTERVAL 1 SECOND");
expect(!unitApplyApproval($db,$r,$cfg,'subject-1'),'Expired request must not apply');
$u=['unitId'=>7,'ownerId'=>1,'peer'=>'100.68.1.3'];
expect(!unitRequestBindingValid($r,$cfg,$u,'https://other.example/api/identity'),'Provider change must fail');
$u['peer']='100.68.1.4'; expect(!unitRequestBindingValid($r,$cfg,$u,$provider),'IP change must fail');
echo "Hosted gateway transport, provider, expiry, ownership and replay tests passed\n";
