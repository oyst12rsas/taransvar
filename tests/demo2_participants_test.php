<?php
// Run only against a disposable MariaDB: DEMO2_TEST_PORT=... php this-file.php.
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$port = (int)getenv('DEMO2_TEST_PORT');
if (!$port) throw new RuntimeException('DEMO2_TEST_PORT must identify a disposable DB');
$db = new mysqli('127.0.0.1','root',getenv('DEMO2_TEST_PASSWORD') ?: '', '', $port);
$db->query('CREATE DATABASE IF NOT EXISTS tarasec_demo2_test'); $db->select_db('tarasec_demo2_test');
function sql(mysqli $db, string $text): void {
    $db->multi_query($text);
    do { if ($r=$db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
}
function check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$db->query('SET FOREIGN_KEY_CHECKS=0');
foreach (['demoSshAttempt','demoSshEvent','demoSshSession','demoSshSetup','demoSshNodeB','demoSshIdentityProof','setup','unit','internalInfections','partnerRouter','syslogThreat','hackReport'] as $t) $db->query("DROP TABLE IF EXISTS $t");
$db->query('SET FOREIGN_KEY_CHECKS=1');
sql($db, "CREATE TABLE setup(dbVersion INT,globalDb1ip INT UNSIGNED,globalDb2ip INT UNSIGNED,globalDb3ip INT UNSIGNED);
    INSERT INTO setup VALUES(98,INET_ATON('100.68.126.0'),NULL,NULL);
    CREATE TABLE unit(unitId INT PRIMARY KEY,ipAddress INT UNSIGNED,lastSeen DATETIME,hostname TEXT,description TEXT);
    INSERT INTO unit VALUES(101,INET_ATON('10.47.1.1'),NOW(),'one',''),(102,INET_ATON('10.47.1.2'),NOW(),'two','');
    CREATE TABLE internalInfections(infectionId INT PRIMARY KEY,unitId INT,ip INT UNSIGNED,active BIT,severity INT,why TEXT);
    CREATE TABLE partnerRouter(ip INT UNSIGNED,partnerStatusReceived DATETIME);
    INSERT INTO partnerRouter VALUES(INET_ATON('100.68.165.190'),NOW()),(INET_ATON('100.68.176.110'),NOW()),(INET_ATON('100.68.149.164'),NOW());
    CREATE TABLE syslogThreat(syslogThreatId INT PRIMARY KEY,src_ip INT UNSIGNED,src_port INT,dst_ip INT UNSIGNED,dst_port INT,created DATETIME,is_attack INT,confirmed_unit_id INT,unit_id INT,description TEXT,demoSshSessionId BIGINT);
    CREATE TABLE hackReport(reportId INT AUTO_INCREMENT PRIMARY KEY,ip INT UNSIGNED,port INT,sentByIp INT UNSIGNED,remoteUnitId INT,ownerConfirmedTime DATETIME,created DATETIME DEFAULT CURRENT_TIMESTAMP,lastSeen DATETIME,status TEXT,hrCategory TEXT,why TEXT,severity INT);");
$install = file_get_contents(__DIR__.'/../misc/install.sql');
$start = strpos($install,'create table demoSshNodeB (');
$end = strpos($install,'alter table syslogThreat add demoSshSessionId', $start);
sql($db, substr($install,$start,$end-$start));
preg_match('/#version 99[^\n]*\n(.*?)(?=#\*\*\*\*\*\*\*\*)/s',$install,$migration);
$migration = preg_replace('/^#.*$/m','',$migration[1]);
sql($db,$migration); sql($db,$migration);
check((int)$db->query('SELECT dbVersion FROM setup')->fetch_row()[0]===99,'migration is idempotent');
sql($db,"INSERT INTO demoSshNodeB(name,ip,port,username,passwordPlain,passwordHash,sensorTokenHash,credentialGeneration)
    VALUES('B',INET_ATON('100.68.149.164'),22,'demo','1',SHA2('1',256),SHA2('sensor_token',256),1);
    INSERT INTO demoSshSetup(name,nodeAIp,nodeAPort,demoSshNodeBId) VALUES('class',INET_ATON('100.68.176.110'),22,1);");
$temp = sys_get_temp_dir().'/tarasec-demo2-api-'.bin2hex(random_bytes(5)); mkdir($temp); mkdir($temp.'/script');
file_put_contents($temp.'/dbfunc.php', <<<'PHP'
<?php
function getConnection(){return new mysqli('127.0.0.1','root',getenv('DEMO2_TEST_PASSWORD')?:'','tarasec_demo2_test',(int)getenv('DEMO2_TEST_PORT'));}
PHP);
file_put_contents($temp.'/taraLib.php','<?php');
foreach (['appDemoSshSession.php','appDemoSshIdentity.php','demoSshIdentityLib.php'] as $file) copy(__DIR__.'/../html/script/'.$file,$temp.'/script/'.$file);
file_put_contents($temp.'/invoke.php', <<<'PHP'
<?php
$case=json_decode(base64_decode($argv[2]),true);
$_SERVER=$case['server']; $_GET=$case['get']??[]; $_POST=$case['post']??[];
// The only simulated boundary is DB->gateway HTTP transport. Its responses
// below were obtained by executing the real gateway API against this fixture.
class GatewayProofTransport {
    public $context; private string $body=''; private int $position=0;
    function stream_open($url,$mode,$options,&$path):bool {
        if ($url!=='http://100.68.165.190/script/appDemoSshIdentity.php') return false;
        parse_str(stream_context_get_options($this->context)['http']['content'],$form);
        $proofs=json_decode(file_get_contents(__DIR__.'/proofs.json'),true);
        $this->body=json_encode($proofs[$form['identity_proof']]??['ok'=>false]); return true;
    }
    function stream_read($count):string { $r=substr($this->body,$this->position,$count); $this->position+=strlen($r); return $r; }
    function stream_eof():bool { return $this->position>=strlen($this->body); }
    function stream_stat():array { return []; }
}
stream_wrapper_unregister('http'); stream_wrapper_register('http',GatewayProofTransport::class);
chdir(__DIR__.'/script'); include $argv[1];
PHP);
function api(string $temp,string $file,array $case):array {
    $p=proc_open([PHP_BINARY,'-n','-d','extension='.ini_get('extension_dir').'/mysqlnd.so','-d','extension='.ini_get('extension_dir').'/mysqli.so',$temp.'/invoke.php',$file,base64_encode(json_encode($case))],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($p)) throw new RuntimeException('API failed: '.$err.$out);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
$gateway=['REQUEST_METHOD'=>'POST','REMOTE_ADDR'=>'10.47.1.1'];
$one=api($temp,'appDemoSshIdentity.php',['server'=>$gateway,'post'=>['action'=>'issue','unit_id'=>102]]);
$gateway['REMOTE_ADDR']='10.47.1.2'; $gateway['HTTP_CLIENT_IP']='10.47.1.1';
$two=api($temp,'appDemoSshIdentity.php',['server'=>$gateway,'post'=>['action'=>'issue']]);
$resolve=['REQUEST_METHOD'=>'POST','REMOTE_ADDR'=>'100.68.126.0'];
$proofs=[];
foreach ([$one,$two] as $item) $proofs[$item['identity_proof']]=api($temp,'appDemoSshIdentity.php',['server'=>$resolve,'post'=>['action'=>'resolve','identity_proof'=>$item['identity_proof']]]);
check($proofs[$one['identity_proof']]['unit_id']===101 && $proofs[$two['identity_proof']]['unit_id']===102,'gateway observes units and ignores forged unit/IP headers');
$bad=api($temp,'appDemoSshIdentity.php',['server'=>$gateway,'post'=>['action'=>'resolve','identity_proof'=>$one['identity_proof']]]);
check(!$bad['ok'],'only configured DB may redeem identity proofs');
file_put_contents($temp.'/proofs.json',json_encode($proofs));
$server=['REQUEST_METHOD'=>'POST','REMOTE_ADDR'=>'100.68.165.190'];
$a=api($temp,'appDemoSshSession.php',['server'=>$server,'post'=>['action'=>'create','setup_id'=>1,'identity_proof'=>$one['identity_proof']]]);
$b=api($temp,'appDemoSshSession.php',['server'=>$server,'post'=>['action'=>'create','setup_id'=>1,'identity_proof'=>$two['identity_proof']]]);
check($a['ok'] && $b['ok'] && $a['session_id']!==$b['session_id'] && $a['unit_id']===101 && $b['unit_id']===102,'two units behind one gateway start independent sessions');
check($a['expires_in']===1800,'classroom expiry agrees with SQL');
$dup=api($temp,'appDemoSshSession.php',['server'=>$server,'post'=>['action'=>'create','setup_id'=>1,'identity_proof'=>$one['identity_proof']]]);
check(!$dup['ok'] && $dup['active_session_id']===$a['session_id'],'same verified unit cannot start a duplicate session');
$forged=api($temp,'appDemoSshSession.php',['server'=>$server,'post'=>['action'=>'create','setup_id'=>1,'unit_id'=>999]]);
check(!$forged['ok'],'bare unit ID is rejected');
function status(string $temp,array $server,array $session):array {
    $server['REQUEST_METHOD']='GET';
    return api($temp,'appDemoSshSession.php',['server'=>$server,'get'=>['action'=>'status','session_id'=>$session['session_id'],'session_token'=>$session['session_token']]])['session'];
}
sql($db,"INSERT INTO syslogThreat VALUES(1,INET_ATON('100.68.165.190'),41001,INET_ATON('100.68.176.110'),22,NOW(),1,NULL,999,'rejection',NULL);
    INSERT INTO hackReport(ip,port,sentByIp,remoteUnitId,ownerConfirmedTime,lastSeen) VALUES(INET_ATON('100.68.165.190'),41001,INET_ATON('100.68.176.110'),101,NOW(),NOW());");
$sa=status($temp,$server,$a); $sb=status($temp,$server,$b);
check($sa['node_a_observed'] && !$sb['node_a_observed'],'Node A evidence is isolated despite shared IP and receiver-local ID');
// Node B callback arrives before confession. It must not claim either session.
$sensor=['REQUEST_METHOD'=>'POST','REMOTE_ADDR'=>'100.68.149.164','HTTP_X_TARASEC_NODE_TOKEN'=>'sensor_token'];
$attempt=api($temp,'appDemoSshSession.php',['server'=>$sensor,'post'=>['action'=>'validate','source_ip'=>'100.68.165.190','source_port'=>42001,'destination_port'=>22,'username'=>'demo','password'=>'1']]);
check($attempt['accepted'] && $attempt['state']==='awaiting_attribution','unknown tuple waits for attribution instead of selecting newest session');
sql($db,"INSERT INTO syslogThreat VALUES(2,INET_ATON('100.68.165.190'),42001,INET_ATON('100.68.149.164'),22,NOW(),1,NULL,999,'ssh_login_success test',NULL);
    INSERT INTO hackReport(ip,port,sentByIp,remoteUnitId,ownerConfirmedTime,lastSeen) VALUES(INET_ATON('100.68.165.190'),42001,INET_ATON('100.68.149.164'),101,NOW(),NOW());");
$sa=status($temp,$server,$a); $sb=status($temp,$server,$b);
check($sa['state']==='cleared' && $sa['attempts']===1 && $sa['node_b_observed'] && $sb['attempts']===0 && !$sb['node_b_observed'],'late confession attaches and clears only the correct participant');
// An already-attributed callback must select unit 102, never completed unit 101.
sql($db,"INSERT INTO syslogThreat VALUES(3,INET_ATON('100.68.165.190'),41002,INET_ATON('100.68.176.110'),22,NOW(),1,NULL,999,'rejection',NULL),
    (4,INET_ATON('100.68.165.190'),42002,INET_ATON('100.68.149.164'),22,NOW(),1,NULL,999,'ssh_login_success test',NULL);
    INSERT INTO hackReport(ip,port,sentByIp,remoteUnitId,ownerConfirmedTime,lastSeen) VALUES
    (INET_ATON('100.68.165.190'),41002,INET_ATON('100.68.176.110'),102,NOW(),NOW()),
    (INET_ATON('100.68.165.190'),42002,INET_ATON('100.68.149.164'),102,NOW(),NOW());");
$second=api($temp,'appDemoSshSession.php',['server'=>$sensor,'post'=>['action'=>'validate','source_ip'=>'100.68.165.190','source_port'=>42002,'destination_port'=>22,'username'=>'demo','password'=>'1']]);
check($second['state']==='cleared' && status($temp,$server,$b)['attempts']===1 && status($temp,$server,$a)['attempts']===1,'attributed Node B callback completes only unit 102');
// Start a fresh session for cancellation checks.
$b=api($temp,'appDemoSshSession.php',['server'=>$server,'post'=>['action'=>'create','setup_id'=>1,'identity_proof'=>$two['identity_proof']]]);
$cancel=api($temp,'appDemoSshSession.php',['server'=>$server,'post'=>['action'=>'cancel','session_id'=>$b['session_id'],'session_token'=>$a['session_token']]]);
check(!$cancel['ok'],'another session token cannot cancel a participant');
$cancel=api($temp,'appDemoSshSession.php',['server'=>$server,'post'=>['action'=>'cancel','session_id'=>$b['session_id'],'session_token'=>$b['session_token']]]);
check($cancel['state']==='cancelled' && status($temp,$server,$a)['state']==='cleared','cancellation remains isolated');
// Proofs expire, and local infection policy is checked at redemption.
$db->query("UPDATE demoSshIdentityProof SET expires=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE tokenHash=SHA2('".$one['identity_proof']."',256)");
$expired=api($temp,'appDemoSshIdentity.php',['server'=>$resolve,'post'=>['action'=>'resolve','identity_proof'=>$one['identity_proof']]]);
check(!$expired['ok'],'expired gateway identity proof is rejected');
$db->query("INSERT INTO internalInfections VALUES(1,102,INET_ATON('10.47.1.2'),b'1',7,'real security finding')");
$blocked=api($temp,'appDemoSshIdentity.php',['server'=>$resolve,'post'=>['action'=>'resolve','identity_proof'=>$two['identity_proof']]]);
check(!$blocked['eligible'],'gateway rechecks current unit infection before starting');
$proofs[$two['identity_proof']]=$blocked; file_put_contents($temp.'/proofs.json',json_encode($proofs));
$blockedCreate=api($temp,'appDemoSshSession.php',['server'=>$server,'post'=>['action'=>'create','setup_id'=>1,'identity_proof'=>$two['identity_proof']]]);
check(!$blockedCreate['ok'] && $blockedCreate['remediation_required'],'DB enforces the verified unit security policy');
echo "Demo 2 participant regression checks passed\n";
