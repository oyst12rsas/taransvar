<?php
// Real API/SQL checks against a disposable MariaDB only.
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$port=(int)getenv('DEMO3_TEST_PORT');
if(!$port) throw new RuntimeException('Set DEMO3_TEST_PORT to a disposable MariaDB');
$db=new mysqli('127.0.0.1','root',getenv('DEMO3_TEST_PASSWORD')?:'','',$port);
$db->query('CREATE DATABASE IF NOT EXISTS tarasec_demo3_test'); $db->select_db('tarasec_demo3_test');
function sql3(mysqli $db,string $text):void {
    $db->multi_query($text); do {if($r=$db->store_result())$r->free();}while($db->more_results()&&$db->next_result());
}
function check3(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$db->query('SET FOREIGN_KEY_CHECKS=0');
foreach(['demoAssistanceParticipant','demoAssistanceSession','assistanceRequest','setup']as $t)$db->query("DROP TABLE IF EXISTS $t");
$db->query('SET FOREIGN_KEY_CHECKS=1');
$install=file_get_contents(__DIR__.'/../misc/install.sql');
$start=strpos($install,'CREATE TABLE IF NOT EXISTS demoAssistanceSession (');
$end=strpos($install,'ALTER TABLE demoAssistanceSession',$start);
sql3($db,substr($install,$start,$end-$start));
sql3($db,"CREATE TABLE setup(globalDb1ip INT UNSIGNED,globalDb2ip INT UNSIGNED,globalDb3ip INT UNSIGNED);
    INSERT INTO setup VALUES(INET_ATON('100.68.126.0'),NULL,NULL);
    CREATE TABLE assistanceRequest(requestId INT AUTO_INCREMENT PRIMARY KEY,purpose VARCHAR(32),ip INT UNSIGNED,port INT,senderIp INT UNSIGNED,senderPort INT,category VARCHAR(64),comment TEXT,requestQuality INT,wantSpoofed BIT,active BIT,isDemo BIT DEFAULT b'0',handled BIT,regardingRequestId INT,fromOther BIT,sentPartners BIT DEFAULT b'0',handlingComment TEXT);
    INSERT INTO assistanceRequest(purpose,ip,port,category,active,isDemo) VALUES('forDistribution',INET_ATON('100.68.1.1'),0,'production',b'1',b'0');");
$temp=sys_get_temp_dir().'/tarasec-demo3-api-'.bin2hex(random_bytes(5));mkdir($temp);mkdir($temp.'/script');
file_put_contents($temp.'/dbfunc.php', <<<'PHP'
<?php
function getConnection(){return new mysqli('127.0.0.1','root',getenv('DEMO3_TEST_PASSWORD')?:'','tarasec_demo3_test',(int)getenv('DEMO3_TEST_PORT'));}
PHP);
foreach(['appDemoAssistance.php','partnerRequest.php']as $file)copy(__DIR__.'/../html/script/'.$file,$temp.'/script/'.$file);
file_put_contents($temp.'/invoke.php', <<<'PHP'
<?php
$case=json_decode(base64_decode($argv[2]),true);
$_SERVER=$case['server']??['REQUEST_METHOD'=>'POST','REMOTE_ADDR'=>'100.68.165.190'];
$_GET=$case['get']??[];$_POST=$case['post']??[];$_REQUEST=$_GET+$_POST;
// Test names/codes are ASCII; allow the minimal test PHP runtime without mbstring.
if(!function_exists('mb_strlen')){function mb_strlen($text){return strlen($text);}}
chdir(__DIR__.'/script');include $argv[1];
PHP);
function start3(string $temp,string $file,array $case):array {
    $p=proc_open([PHP_BINARY,$temp.'/invoke.php',$file,base64_encode(json_encode($case))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);return[$p,$pipes];
}
function finish3(array $running,bool $json=true):mixed {
    [$p,$pipes]=$running;$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);
    if(proc_close($p))throw new RuntimeException($err.$out);
    return $json?json_decode($out,true,512,JSON_THROW_ON_ERROR):trim($out);
}
function api3(string $temp,array $input):array {return finish3(start3($temp,'appDemoAssistance.php',['post'=>$input]));}
function create3(string $target,string $code=''):array {return ['action'=>'create','name'=>'Class exercise','target_ip'=>$target,'delay_seconds'=>120,'containment_seconds'=>30,'join_code'=>$code];}
function status3(string $temp,int $sid,string $code=''):array {return api3($temp,['action'=>'status','session_id'=>$sid,'join_code'=>$code]);}
$a=api3($temp,create3('100.68.126.0'));$sidA=$a['session']['session_id'];
$b=api3($temp,create3('100.68.127.0'));$sidB=$b['session']['session_id'];
check3($a['ok']&&$b['ok']&&status3($temp,$sidA)['session']['state']==='active','different targets have independent active exercises');
$conflict=api3($temp,create3('100.68.126.0'));
check3(!$conflict['ok']&&$conflict['error']==='target_exercise_already_active'&&$conflict['active_session_id']===$sidA&&$conflict['joinable'],'same target returns existing joinable exercise instead of closing it');
check3((int)$db->query('SELECT COUNT(*) FROM demoAssistanceSession')->fetch_row()[0]===2,'conflicting create does not insert or remove sessions');
$j1=api3($temp,['action'=>'join','session_id'=>$sidA,'nickname'=>'one']);
$j2=api3($temp,['action'=>'join','session_id'=>$sidA,'nickname'=>'two']);
check3($j1['participant_id']!==$j2['participant_id']&&$j1['participant_token']!==$j2['participant_token'],'two participants behind shared IP join with separate credentials');
$db->query("UPDATE demoAssistanceSession SET blockAt=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE sessionId IN ($sidA,$sidB)");
status3($temp,$sidA);status3($temp,$sidB);
check3((int)$db->query("SELECT COUNT(*) FROM assistanceRequest WHERE active=b'1' AND isDemo=b'1'")->fetch_row()[0]===2,'containment requests for both targets coexist');
$contained=api3($temp,create3('100.68.126.0'));
check3(!$contained['ok']&&!$contained['joinable']&&status3($temp,$sidA)['session']['state']==='contained','new create cannot cancel active containment');
$bad=api3($temp,['action'=>'release','session_id'=>$sidA,'controller_token'=>$b['controller_token']]);
check3(!$bad['ok'],'another exercise controller cannot release containment');
$release=api3($temp,['action'=>'release','session_id'=>$sidA,'controller_token'=>$a['controller_token']]);
check3($release['ok']&&status3($temp,$sidB)['session']['state']==='contained','release affects only its own exercise');
check3((int)$db->query("SELECT COUNT(*) FROM assistanceRequest WHERE category='demo3_$sidB' AND active=b'1'")->fetch_row()[0]===1,'other target assistance remains active after release');
$again=api3($temp,['action'=>'release','session_id'=>$sidA,'controller_token'=>$a['controller_token']]);
check3(!$again['ok'],'duplicate release does not queue another event');
api3($temp,['action'=>'leave','session_id'=>$sidA,'participant_token'=>$j1['participant_token']]);
check3(status3($temp,$sidA)['session']['state']==='releasing','one participant leaving does not close the remaining participant exercise');
api3($temp,['action'=>'leave','session_id'=>$sidA,'participant_token'=>$j2['participant_token']]);
check3(status3($temp,$sidA)['session']['state']==='closed','normal closure occurs after all participants leave');
$next=api3($temp,create3('100.68.126.0'));
check3($next['ok']&&status3($temp,$sidB)['session']['state']==='contained','new exercise starts after closure without disturbing another target');
$private=api3($temp,create3('100.68.128.0','secret-group'));
$hidden=api3($temp,create3('100.68.128.0'));
check3(!$hidden['ok']&&!isset($hidden['active_session_id'])&&!$hidden['joinable'],'private exercise conflict does not disclose its ID');
$visible=api3($temp,create3('100.68.128.0','secret-group'));
check3($visible['active_session_id']===$private['session']['session_id']&&$visible['joinable'],'correct group code identifies the existing exercise');
// Two creators race for an unused target: exactly one may start.
$r1=start3($temp,'appDemoAssistance.php',['post'=>create3('100.68.129.0')]);
$r2=start3($temp,'appDemoAssistance.php',['post'=>create3('100.68.129.0')]);
$r1=finish3($r1);$r2=finish3($r2);
check3((int)$r1['ok']+(int)$r2['ok']===1,'concurrent same-target creators produce exactly one exercise');
function delivery3(string $temp,string $ip,string $category,int $rid,int $active):string {
    return finish3(start3($temp,'partnerRequest.php',['server'=>['REQUEST_METHOD'=>'GET','REMOTE_ADDR'=>'100.68.126.0'],
        'get'=>['f'=>'assistance','ip'=>dechex(ip2long($ip)),'port'=>0,'cat'=>$category,'rid'=>$rid,'active'=>$active,'qual'=>7,'sp'=>0]]),false);
}
check3(delivery3($temp,'100.68.126.0','demo3_1001',100,1)==='ok'&&delivery3($temp,'100.68.127.0','demo3_1002',200,1)==='ok','gateway accepts requests from independent exercises');
check3((int)$db->query("SELECT COUNT(*) FROM assistanceRequest WHERE purpose='fromPartner' AND active=b'1'")->fetch_row()[0]===2,'new gateway delivery preserves other exercise requests');
delivery3($temp,'100.68.126.0','demo3_1001',101,0);
delivery3($temp,'100.68.126.0','demo3_1001',100,1);
check3((int)$db->query("SELECT CAST(active AS UNSIGNED) FROM assistanceRequest WHERE purpose='fromPartner' AND category='demo3_1001'")->fetch_row()[0]===0,'late start cannot overwrite retained release tombstone');
check3((int)$db->query("SELECT CAST(active AS UNSIGNED) FROM assistanceRequest WHERE purpose='fromPartner' AND category='demo3_1002'")->fetch_row()[0]===1,'release and late delivery preserve other exercise');
check3((int)$db->query("SELECT COUNT(*) FROM assistanceRequest WHERE category='production' AND active=b'1'")->fetch_row()[0]===1,'production assistance remains untouched');
echo "Demo 3 isolation regression checks passed\n";
