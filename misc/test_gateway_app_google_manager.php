<?php
// App-link approval by the node's enrolled Google manager (no local user row needed).
// Run as root (the manager list must be root-owned): sudo php misc/test_gateway_app_google_manager.php
declare(strict_types=1);
$managers=tempnam(sys_get_temp_dir(),'gkm');
define('TARA_GATEKEEPER_MANAGERS',$managers);
require_once __DIR__.'/gateway_app_poll.php';
function expect(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
if (posix_geteuid()!==0) { fwrite(STDERR,"Run as root.\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new mysqli(getenv('TARA_TEST_DB_HOST') ?: '127.0.0.1',getenv('TARA_TEST_DB_USER') ?: 'root',getenv('TARA_TEST_DB_PASS') ?: 'test',getenv('TARA_TEST_DB_NAME') ?: 'hosted_link_test',3306);
$db->query('DROP TABLE IF EXISTS gatewayAppRequest, gatewayAppLink');
$db->multi_query((string)file_get_contents(__DIR__.'/gateway_app_link.sql')); while ($db->more_results() && $db->next_result()) {}
$db->query('CREATE TABLE IF NOT EXISTS user (userId INT PRIMARY KEY, username VARCHAR(255), isAdmin BIT(1) NOT NULL DEFAULT 0)');
$cols=array_column($db->query('SHOW COLUMNS FROM user')->fetch_all(MYSQLI_ASSOC),'Field');
if (!in_array('username',$cols,true)) $db->query('ALTER TABLE user ADD COLUMN username VARCHAR(255)');
$db->query('UPDATE user SET isAdmin=0');

file_put_contents($managers,json_encode([hash('sha256','owner@example.org')])."\n");
chown($managers,0); chmod($managers,0644);
$cfg=['mode'=>'hosted_gateway','gateway_id'=>str_repeat('a',32),'subject_key'=>str_repeat('b',64),'base_url'=>'http://node.local','transport'=>'account_service'];
$req=function(string $id,string $email,string $state='decision'): array {
    return ['requestId'=>str_repeat($id,32),'subject'=>'phone-google-subject','clientHash'=>gatewayAppClient(str_repeat('c',32)),'confirmationCode'=>'ABCD1234','adminEmail'=>$email,'state'=>$state,'expiresAt'=>gmdate('Y-m-d H:i:s',time()+600)];
};

expect(gatewayApplyHosted($db,$cfg,$req('1','ordinary@example.org'))==='rejected','Unlisted Google identity cannot authorize');
expect(gatewayApplyHosted($db,$cfg,$req('2',' Owner@Example.org '))==='approved','Enrolled Gatekeeper Google manager authorizes without a local user row');
$row=$db->query("SELECT approvedByUserId,approvedByManagerHash FROM gatewayAppRequest WHERE requestId='".str_repeat('2',32)."'")->fetch_assoc();
expect((int)$row['approvedByUserId']===0 && $row['approvedByManagerHash']===hash('sha256','owner@example.org'),'Decision records the manager hash, not a user id');
expect(gatewayApplyHosted($db,$cfg,$req('2','owner@example.org'))==='approved','Acknowledgement retry safe');

file_put_contents($managers,"[]\n");
$db->query('DELETE FROM gatewayAppLink');
expect(gatewayApplyHosted($db,$cfg,$req('3','owner@example.org'))==='rejected','Removing the manager stops new approvals');

file_put_contents($managers,json_encode([hash('sha256','owner@example.org')])."\n"); chmod($managers,0666);
expect(gatewayApplyHosted($db,$cfg,$req('4','owner@example.org'))==='rejected','World-writable manager list is not trusted');
chmod($managers,0644);

try { gatewayAppDecide($db,$cfg,str_repeat('5',32),0,true,null); throw new RuntimeException('Manager path needs an email'); }
catch (UnitLinkException $e) {}

unlink($managers);
echo "Gatekeeper Google manager approval, revocation, retry and list-integrity checks passed\n";
