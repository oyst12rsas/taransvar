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
check(webPokeGatewaySession($token,$peer)['unit_id']===20,'Session bound to gateway-observed owner/unit');
denied(fn()=>webPokeGatewaySession($token,'10.100.0.151'),'Another unit cannot reuse a browser token');
$db->query('UPDATE unit SET ownerId=18 WHERE unitId=20');
denied(fn()=>webPokeGatewaySession($token,$peer),'Owner change invalidates session');
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
$expired=$session; $expired['expires']=time()-1; webPokeRecord('gateway',$token,fn($row)=>$expired);
denied(fn()=>webPokeGatewaySession($token,$peer),'Expired session rejected');
$db->close();
echo "MariaDB owner/unit binding and real session-specific mark/reset operations passed.\n";
