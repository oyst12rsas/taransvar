<?php
// Run against a disposable DB only; CI provisions an isolated MariaDB service.
require_once __DIR__ . '/../html/script/webPokeGatewayLib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!(int)getenv('WEB_POKE_TEST_PORT')) throw new RuntimeException('WEB_POKE_TEST_PORT required');
function getConnection() {
    return new mysqli('127.0.0.1','root',getenv('WEB_POKE_TEST_PASSWORD') ?: '', 'tarasec_web_poke_test',(int)getenv('WEB_POKE_TEST_PORT'));
}
$bootstrap=new mysqli('127.0.0.1','root',getenv('WEB_POKE_TEST_PASSWORD') ?: '', '',(int)getenv('WEB_POKE_TEST_PORT'));
$bootstrap->query('CREATE DATABASE IF NOT EXISTS tarasec_web_poke_test'); $bootstrap->close();
$db=getConnection();
function check($ok,$message) { if(!$ok) throw new RuntimeException($message); }
function denied(callable $f,$message) { try { $f(); } catch(DomainException $e) {return;} throw new RuntimeException($message); }
$db->query('DROP TABLE IF EXISTS setup');
$db->query('CREATE TABLE setup(internalIP INT UNSIGNED,nettmask INT UNSIGNED)');
$db->query("INSERT INTO setup VALUES(INET_ATON('10.100.0.1'),INET_ATON('255.255.255.0'))");
$db->query('DROP TABLE IF EXISTS internalInfections'); $db->query('DROP TABLE IF EXISTS unit');
$db->query("CREATE TABLE internalInfections(infectionId INT PRIMARY KEY AUTO_INCREMENT,ip INT UNSIGNED,nettmask INT UNSIGNED,unitId INT,status VARCHAR(20),handled BIT,active BIT,lastSeen DATETIME,severity INT,why VARCHAR(255)) ENGINE=InnoDB");
$db->query('CREATE TABLE unit(unitId INT PRIMARY KEY,ownerId INT,ipAddress INT UNSIGNED,lastSeen DATETIME) ENGINE=InnoDB');
$db->query("INSERT INTO unit VALUES(20,17,INET_ATON('10.100.0.150'),NOW()),(21,17,INET_ATON('10.100.0.151'),NOW())");
denied(fn()=>webPokeLocalUnit('100.68.165.190'),'Remote gateway cannot claim local-unit role');
$peer='10.100.0.150'; $id=bin2hex(random_bytes(32)); $token=bin2hex(random_bytes(32));
$session=['owner_id'=>17,'unit_id'=>20,'client_ip'=>$peer,'session_id'=>$id,'expires'=>time()+1800];
webPokeRecord('gateway',$token,fn($row)=>$session);
check(webPokeGatewaySession($token,$peer)['unit_id']===20,'Session retains optional gateway-observed owner/unit');
denied(fn()=>webPokeGatewaySession($token,'10.100.0.151'),'Another unit cannot reuse a browser token');
$db->query('UPDATE unit SET ownerId=18 WHERE unitId=20');
check(webPokeGatewaySession($token,$peer)['client_ip']===$peer,'Registration changes do not block a visitor session');
$db->query('UPDATE unit SET ownerId=17 WHERE unitId=20');
$db->query("INSERT INTO internalInfections(ip,unitId,why,active,severity) VALUES(INET_ATON('$peer'),20,'Real incident',b'1',8),(INET_ATON('$peer'),20,'DEMO:WEB_POKE:another-session',b'1',3)");
webPokeLocalControl($session,true); webPokeLocalControl($session,true);
check((int)$db->query('SELECT COUNT(*) FROM internalInfections')->fetch_row()[0]===3,'Repeated mark updates own row');
webPokeLocalControl($session,false);
check((int)$db->query("SELECT COUNT(*) FROM internalInfections WHERE active=b'1'")->fetch_row()[0]===2,'Reset leaves genuine findings and another session active');
check(webPokeLocalState($peer)['state']==='infected','Independent findings remain visible after demo reset');
$db->query("UPDATE internalInfections SET active=b'0'");
webPokeLocalControl($session,true); check(webPokeLocalState($peer)['state']==='infected','Gateway reports actual mark');
webPokeLocalControl($session,false); check(webPokeLocalState($peer)['state']==='clean','Gateway reports actual reset');
// A visitor may have no unit row, or a unit row without an owner.
$guestPeer='10.100.0.152';
check(webPokeLocalUnit($guestPeer)===['owner_id'=>0,'unit_id'=>0],'No registration required');
$db->query("INSERT INTO unit VALUES(22,0,INET_ATON('10.100.0.153'),NOW())");
check(webPokeLocalUnit('10.100.0.153')===['owner_id'=>0,'unit_id'=>22],'Owner registration is optional');
$guest=['owner_id'=>0,'unit_id'=>0,'client_ip'=>$guestPeer,'session_id'=>bin2hex(random_bytes(32)),'expires'=>time()+1800];
$guestToken=bin2hex(random_bytes(32)); webPokeRecord('gateway',$guestToken,fn($row)=>$guest);
check(webPokeGatewaySession($guestToken,$guestPeer)['unit_id']===0,'Guest capability accepted from its socket peer');
denied(fn()=>webPokeGatewaySession($guestToken,'10.100.0.154'),'Another unregistered visitor cannot reuse token');
denied(fn()=>webPokeGatewaySession($guestToken,'100.68.165.190'),'Guest token cannot bypass local subnet');
$count=(int)$db->query('SELECT COUNT(*) FROM unit')->fetch_row()[0];
webPokeLocalControl($guest,true); webPokeLocalControl($guest,true);
check(webPokeLocalState($guestPeer)['state']==='infected','Unregistered visitor can mark');
check((int)$db->query("SELECT COUNT(*) FROM internalInfections WHERE ip=INET_ATON('$guestPeer')")->fetch_row()[0]===1,'Guest mark is idempotent');
webPokeLocalControl($guest,false);
check(webPokeLocalState($guestPeer)['state']==='clean','Unregistered visitor can reset');
check((int)$db->query('SELECT COUNT(*) FROM unit')->fetch_row()[0]===$count,'Demo does not invent device registrations');
$db->query("INSERT INTO unit VALUES(23,19,INET_ATON('$guestPeer'),NOW())");
check(webPokeGatewaySession($guestToken,$guestPeer)['unit_id']===0,'Discovery/registration during a guest session does not invalidate its token');
webPokeLocalControl($guest,true); webPokeLocalControl($guest,false);
check(webPokeLocalState($guestPeer)['state']==='clean','Guest reset still owns its original mark after registration');
$expired=$session; $expired['expires']=time()-1; webPokeRecord('gateway',$token,fn($row)=>$expired);
denied(fn()=>webPokeGatewaySession($token,$peer),'Expired session rejected');
// Restart recovers stale web-demo marks, including before registration, but
// preserves other devices, other known units and security findings.
$db->query("UPDATE internalInfections SET active=b'0'");
$db->query("INSERT INTO internalInfections(ip,unitId,why,active,severity) VALUES
    (INET_ATON('$peer'),20,'DEMO:WEB_POKE:expired',b'1',3),
    (INET_ATON('$peer'),0,'DEMO:WEB_POKE:guest-before-registration',b'1',3),
    (INET_ATON('$peer'),99,'DEMO:WEB_POKE:another-unit',b'1',3),
    (INET_ATON('10.100.0.151'),21,'DEMO:WEB_POKE:another-device',b'1',3),
    (INET_ATON('$peer'),20,'DEMO:other-demo',b'1',3),
    (INET_ATON('$peer'),20,'Real incident',b'1',8)");
$before=webPokeLocalState($peer);
check($before['sources']===['web_poke'=>3,'other_demo'=>1,'security_finding'=>1],'State distinguishes demo marks and security findings');
check(webPokeNeedsRemediation($before),'A separate security finding blocks participation');
webPokeRestartDevice($session);
check((int)$db->query("SELECT COUNT(*) FROM internalInfections WHERE active=b'1'")->fetch_row()[0]===3,'Restart clears matching device demo rows, including older app/demo marks');
check(webPokeNeedsRemediation(webPokeLocalState($peer)),'Restart cannot bypass a security finding');
$db->query("UPDATE internalInfections SET active=b'0' WHERE why='Real incident'");
check(!webPokeNeedsRemediation(webPokeLocalState($peer)),'Demo-only infections do not require remediation');
$db->query("UPDATE internalInfections SET active=b'0'");
check(webPokeLocalState($peer)['state']==='clean','No findings means a clean gateway assessment');
// Only verified SSH sessions for this exact gateway/unit can be closed.
require_once __DIR__ . '/../html/script/webPokeCoordinatorLib.php';
$db->query('DROP TABLE IF EXISTS demoSshSession');
check(webPokeCloseSshDemos('100.68.165.190',20)===0,'Optional absent SSH demo schema is supported');
$db->query("CREATE TABLE demoSshSession(id INT PRIMARY KEY,sourceIp INT UNSIGNED,unitId INT,gatewayVerified BIT,state VARCHAR(40),completed DATETIME,expires DATETIME,lastSeen DATETIME)");
$db->query("INSERT INTO demoSshSession(id,sourceIp,unitId,gatewayVerified,state,expires) VALUES
 (1,INET_ATON('100.68.165.190'),20,b'1','awaiting_node_a',NOW()+INTERVAL 1 HOUR),
 (2,INET_ATON('100.68.165.190'),21,b'1','demo_infected',NOW()+INTERVAL 1 HOUR),
 (3,INET_ATON('100.68.165.190'),20,b'0','awaiting_node_a',NOW()+INTERVAL 1 HOUR),
 (4,INET_ATON('100.68.176.110'),20,b'1','awaiting_node_b',NOW()+INTERVAL 1 HOUR),
 (5,INET_ATON('100.68.165.190'),20,b'1','cleared',NOW()+INTERVAL 1 HOUR)");
check(webPokeCloseSshDemos('100.68.165.190',0)===0,'Anonymous visitors cannot close shared-address SSH sessions');
check(webPokeCloseSshDemos('100.68.165.190',20)===1,'Only exact verified gateway/unit SSH session cancelled');
check($db->query('SELECT state FROM demoSshSession WHERE id=1')->fetch_row()[0]==='cancelled','Previous SSH demo is terminal');
check((int)$db->query("SELECT COUNT(*) FROM demoSshSession WHERE state='cancelled'")->fetch_row()[0]===1,'Other devices and unverified sessions preserved');
$db->close();
echo "MariaDB visitor binding and real session-specific mark/reset operations passed.\n";
