<?php
declare(strict_types=1);
require_once __DIR__.'/gateway_app_poll.php';
function expect(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','test','hosted_link_test',3306);
$db->query('ALTER TABLE user ADD COLUMN username VARCHAR(255)');
$db->query("UPDATE user SET isAdmin=1,username='admin@example.org' WHERE userId=1");
$cfg=['mode'=>'hosted_gateway','gateway_id'=>str_repeat('a',32),'subject_key'=>str_repeat('b',64),'base_url'=>'http://node.local','transport'=>'account_service'];
unitLinkValidateConfig($cfg);
$other=$cfg; $other['base_url']='http://192.168.50.1'; unitLinkValidateConfig($other);
$other['base_url']='http://203.0.113.7'; unitLinkValidateConfig($other);
expect(unitServiceNodeId($cfg)===unitServiceNodeId($other),'Changing route or address never changes node identity');
$r=['requestId'=>str_repeat('f',32),'subject'=>'phone-google-subject','clientHash'=>gatewayAppClient(str_repeat('c',32)),'confirmationCode'=>'ABCD1234','adminEmail'=>'ordinary@example.org','state'=>'decision','expiresAt'=>gmdate('Y-m-d H:i:s',time()+600)];
expect(gatewayApplyHosted($db,$cfg,$r)==='rejected','Ordinary Google identity cannot authorize');
$r['adminEmail']='admin@example.org'; expect(gatewayApplyHosted($db,$cfg,$r)==='approved','Current node administrator authorizes app');
expect(gatewayApplyHosted($db,$cfg,$r)==='approved','Acknowledgement retry safe');
$r['state']='revoking'; expect(gatewayApplyHosted($db,$cfg,$r)==='revoked','Revoke disables local grant');
$r['state']='decision'; expect(gatewayApplyHosted($db,$cfg,$r)==='revoked','Old request cannot reapply after revocation');
$r['requestId']=str_repeat('e',32); $db->query('UPDATE user SET isAdmin=0 WHERE userId=1');
expect(gatewayApplyHosted($db,$cfg,$r)==='rejected','Demoted administrator cannot authorize new request');
$_SERVER=['REMOTE_ADDR'=>'192.168.50.2','SERVER_ADDR'=>'192.168.50.1','SERVER_PORT'=>'80'];
try { unitLinkTransport($cfg,'anything'); throw new RuntimeException('Legacy credential endpoint should remain closed on HTTP'); } catch(UnitLinkException $e) {}
echo "Network-independent node identity, local administrator authorization, demotion, retry/revocation and legacy credential isolation passed\n";
