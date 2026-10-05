<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$socket=getenv('DEMO5_TEST_SOCKET');
$port=(int)(getenv('DEMO5_TEST_PORT')?:0);
if (!$port && (!$socket || !str_starts_with($socket,'/tmp/'))) throw new RuntimeException('Provide a disposable DB with DEMO5_TEST_SOCKET or DEMO5_TEST_PORT');
$db=new mysqli($port?'127.0.0.1':'localhost','root',getenv('DEMO5_TEST_PASSWORD')?:'','',$port,$port?null:$socket);
$db->query('CREATE DATABASE IF NOT EXISTS tarasec_demo5_test');$db->select_db('tarasec_demo5_test');
function sqlFile(mysqli $db,string $sql):void {
    $db->multi_query($sql);do {if($r=$db->store_result())$r->free();}while($db->more_results()&&$db->next_result());
}
function check(bool $condition,string $case):void {if(!$condition)throw new RuntimeException($case);}
sqlFile($db,"CREATE TABLE IF NOT EXISTS setup (isGlobalDbServer BIT,adminIP INT UNSIGNED);
CREATE TABLE IF NOT EXISTS partnerRouter(routerId INT PRIMARY KEY,ip INT UNSIGNED,nettmask INT UNSIGNED);
CREATE TABLE IF NOT EXISTS colorListings(ip INT UNSIGNED PRIMARY KEY,color ENUM('white','black'),active BIT,handled BIT);
CREATE TABLE IF NOT EXISTS domain(domainId INT PRIMARY KEY,color ENUM('white','black'));
CREATE TABLE IF NOT EXISTS domainIp(domainId INT,ip INT UNSIGNED,handled BIT);
DELETE FROM setup; INSERT INTO setup VALUES(b'1',INET_ATON('100.68.126.0'));");
$migration=file_get_contents(__DIR__.'/../misc/demo5_partner_containment.sql');sqlFile($db,$migration);sqlFile($db,$migration);
foreach(['partnerRestrictionDelivery','partnerIncidentEvidence','demo5Session','demo5Configuration','partnerRestrictionReceiver','partnerRouter','colorListings'] as $table)$db->query("DELETE FROM $table");
$db->query("INSERT INTO partnerRouter(routerId,ip,nettmask,demo5Enabled) VALUES(1,INET_ATON('100.68.165.190'),4294967295,1)");
$db->query("INSERT INTO demo5Configuration VALUES(1,INET_ATON('100.68.176.110'),22,30,120)");
$db->query("INSERT INTO partnerRestrictionReceiver VALUES(INET_ATON('100.68.176.110'),SHA2('receiver_token',256),1),(INET_ATON('100.68.149.164'),SHA2('receiver2_token',256),1),(INET_ATON('100.68.165.190'),SHA2('offender',256),1),(INET_ATON('100.68.126.0'),SHA2('db',256),1)");
$id=str_repeat('a',32);
$q=$db->prepare("INSERT INTO demo5Session(sessionId,tokenHash,routerId,sourceIp,receiverIp,receiverPort,created,expiresAt)
VALUES(?,SHA2('session_token',256),1,INET_ATON('100.68.165.190'),INET_ATON('100.68.176.110'),22,DATE_SUB(NOW(),INTERVAL 90 SECOND),DATE_ADD(NOW(),INTERVAL 600 SECOND))");$q->bind_param('s',$id);$q->execute();
require_once __DIR__.'/../html/script/partnerIncidentLib.php';
$_SERVER['SERVER_ADDR']='100.68.126.0';
partnerIncidentRecord($db,1,'100.68.165.190',41000,'100.68.176.110',0,time()-60);
$r=$db->query('SELECT * FROM demo5Session')->fetch_assoc();check($r['state']==='reported' && $r['restrictedAt']===null,'Initial zero tag must not blacklist');
partnerIncidentRecord($db,2,'100.68.165.190',41001,'100.68.149.164',0,time());
check((int)$db->query('SELECT COUNT(*) n FROM partnerIncidentEvidence')->fetch_assoc()['n']===1,'Unconfigured receiver cannot trigger session');
$db->query("UPDATE demo5Session SET notifiedAt=DATE_SUB(NOW(),INTERVAL 40 SECOND)");
partnerIncidentRecord($db,3,'100.68.165.190',41002,'100.68.176.110',null,time());
check($db->query('SELECT restrictedAt FROM demo5Session')->fetch_assoc()['restrictedAt']===null,'Unknown must not blacklist');
partnerIncidentRecord($db,4,'100.68.165.190',41003,'100.68.176.110',42,time());
check($db->query('SELECT taggingState FROM partnerRouter')->fetch_assoc()['taggingState']==='observed','Positive tag evidence updates partner');
$at=time();partnerIncidentRecord($db,5,'100.68.165.190',41004,'100.68.176.110',0,$at);
$r=$db->query('SELECT * FROM demo5Session')->fetch_assoc();check($r['state']==='blacklisted' && $r['restrictionUntil']!==null,'Repeated zero after grace must blacklist');
check($db->query('SELECT taggingState FROM partnerRouter')->fetch_assoc()['taggingState']==='failed','Partner must record failure');
check((int)$db->query('SELECT COUNT(*) n FROM partnerRestrictionDelivery')->fetch_assoc()['n']===2,'Distribution excludes DB and offending gateway');
$until=$r['restrictionUntil'];partnerIncidentRecord($db,5,'100.68.165.190',41004,'100.68.176.110',0,$at);
check($db->query('SELECT restrictionUntil FROM demo5Session')->fetch_assoc()['restrictionUntil']===$until,'Duplicate must not extend restriction');
$db->query("INSERT INTO colorListings VALUES(INET_ATON('100.68.1.1'),'black',b'1',NULL,'manual',NULL),(INET_ATON('100.68.2.2'),'black',b'1',NULL,'partner_db',DATE_ADD(NOW(),INTERVAL 120 SECOND))");
check((int)$db->query('SELECT COUNT(*) n FROM vListings')->fetch_assoc()['n']===1,'Expiring DB restrictions must not stick in legacy kernel list');
echo "Demo 5 migration and database checks passed\n";
