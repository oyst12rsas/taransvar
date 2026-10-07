<?php
declare(strict_types=1);
require_once __DIR__.'/../html/script/gatewayAppLinkCommon.php';
function expect(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
function denied(callable $f): void { try { $f(); } catch (UnitLinkException $e) { return; } throw new RuntimeException('Expected rejection'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','test','hosted_link_test',3306);
$db->query('CREATE TABLE user (userId INT PRIMARY KEY,isAdmin BIT NOT NULL) ENGINE=InnoDB');
$db->query("INSERT INTO user VALUES(1,b'1'),(2,b'0')");
$db->multi_query(file_get_contents(__DIR__.'/gateway_app_link.sql'));
do { if ($r=$db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
$cfg=['gateway_id'=>str_repeat('a',32),'base_url'=>'http://100.68.1.2'];
$subject=str_repeat('b',64); $client=gatewayAppClient(str_repeat('c',32));
$r=gatewayAppRequest($db,$cfg,$subject,$client); $id=$r['request_id'];
expect(str_starts_with($r['approval_url'],$cfg['base_url'].'/gatekeeper/appLink.php?request='),'Approval must target this gateway');
expect(gatewayAppGrant($db,$cfg,$subject,$client)===null,'No access before admin approval');
denied(fn()=>gatewayAppDecide($db,$cfg,$id,2,true));
expect(!gatewayAppAdmin($db,2),'Ordinary login must not authorize approval');
// Browser source is irrelevant: the request remains bound to the stored app.
$_SERVER['REMOTE_ADDR']='100.68.55.44';
gatewayAppDecide($db,$cfg,$id,1,true);
denied(fn()=>gatewayAppDecide($db,$cfg,$id,1,true));
expect(gatewayAppGrant($db,$cfg,$subject,gatewayAppClient(str_repeat('d',32)))===null,'Another app cannot claim this grant');
expect(gatewayAppGrant($db,$cfg,str_repeat('e',64),$client)===null,'Another account cannot claim this grant');
$wrong=$cfg; $wrong['gateway_id']=str_repeat('f',32);
expect(gatewayAppGrant($db,$wrong,$subject,$client)===null,'Another gateway cannot claim this grant');
$token=gatewayAppGrant($db,$cfg,$subject,$client);
expect(is_string($token) && strlen($token)===64,'Approved phone receives credential');
expect($db->query('SELECT tokenHash FROM gatewayAppLink')->fetch_row()[0]===hash('sha256',$token),'Store only token hash');
gatewayAppUnlink($db,$subject,$client);
expect(gatewayAppGrant($db,$cfg,$subject,$client)===null,'Unlink revokes this app');
denied(fn()=>gatewayAppDecide($db,$cfg,$id,1,true));
$r=gatewayAppRequest($db,$cfg,$subject,$client); $id=$r['request_id'];
$db->query("UPDATE gatewayAppRequest SET expiresAt=NOW()-INTERVAL 1 SECOND WHERE requestId='$id'");
denied(fn()=>gatewayAppDecide($db,$cfg,$id,1,true));
$db->query("UPDATE gatewayAppRequest SET expiresAt=NOW()+INTERVAL 1 MINUTE,provider='https://other.example/identity' WHERE requestId='$id'");
denied(fn()=>gatewayAppDecide($db,$cfg,$id,1,true));
$db->query('UPDATE user SET isAdmin=0 WHERE userId=1');
expect(!gatewayAppAdmin($db,1),'Demoted administrator must be rejected');
denied(fn()=>gatewayAppDecide($db,$cfg,$id,1,true));
echo "Gateway app approval, administrator, target, account/client, expiry, replay and revocation checks passed\n";
