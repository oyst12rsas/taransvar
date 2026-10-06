<?php
// Runs against an isolated MariaDB database; central identity verification is stubbed.
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
function check(bool $ok,string $msg): void { if (!$ok) throw new RuntimeException($msg); }
$db=new mysqli('localhost','root','');
$db->query('DROP DATABASE IF EXISTS tarasec_qr_test');
$db->query('CREATE DATABASE tarasec_qr_test'); $db->select_db('tarasec_qr_test');
$db->query('CREATE TABLE setup(adminIP INT UNSIGNED,nettmask INT UNSIGNED)');
$db->query("INSERT INTO setup VALUES(INET_ATON('10.0.0.1'),INET_ATON('255.255.255.0'))");
$db->query('CREATE TABLE unit(unitId INT PRIMARY KEY,ownerId INT NULL,ipAddress INT UNSIGNED,lastSeen TIMESTAMP,hostname VARCHAR(100)) ENGINE=InnoDB');
$db->query("INSERT INTO unit VALUES(1,7,INET_ATON('10.0.0.2'),NOW(),'Laptop')");
foreach (['unit_app_token','unit_google_link','unit_pair_code'] as $name) {
    $sql=file_get_contents(__DIR__.'/../misc/'.$name.'.sql');
    $db->multi_query($sql); do { if($r=$db->store_result())$r->free(); } while($db->more_results() && $db->next_result());
}
$temp=sys_get_temp_dir().'/tarasec-qr-'.bin2hex(random_bytes(6)); mkdir($temp); mkdir($temp.'/script');
$cfg=['gateway_id'=>str_repeat('a',32),'subject_key'=>str_repeat('b',64),'base_url'=>'https://gateway.example'];
file_put_contents($temp.'/config.php','<?php return '.var_export($cfg,true).';');
$common=file_get_contents(__DIR__.'/../html/script/unitLinkCommon.php');
$common=str_replace("'/etc/tarasec/unit-link.php'",var_export($temp.'/config.php',true),$common);
// Only the external Google exchange is substituted; TLS and LAN attribution remain real.
$common=substr($common,0,strpos($common,'function unitRedeemIdentity(')).'function unitRedeemIdentity(string $ticket,array $cfg): string { if ($ticket!==str_repeat("d",64)) throw new UnitLinkException("Bad ticket"); return str_repeat("e",64); }';
file_put_contents($temp.'/script/unitLinkCommon.php',$common);
file_put_contents($temp.'/dbfunc.php',"<?php function getConnection(){return new mysqli('localhost','root','','tarasec_qr_test');}");
copy(__DIR__.'/../html/script/unitPairCode.php',$temp.'/script/unitPairCode.php');
copy(__DIR__.'/../html/link.php',$temp.'/link.php');
file_put_contents($temp.'/invoke.php', <<<'PHP'
<?php
$case=json_decode(base64_decode($argv[2]),true);
$_SERVER=$case['server']; $_POST=$case['post']??[];
if(isset($_POST['csrf'])) {session_id('tarasecqrfixture');session_start();$_SESSION['unit_pair_csrf']=$_POST['csrf'];session_write_close();}
register_shutdown_function(function(){fwrite(STDERR,'HTTP_STATUS='.(string)http_response_code());});
include __DIR__.'/'.$argv[1];
PHP);
function invoke(string $temp,string $file,array $server,array $post=[]): array {
    $p=proc_open([PHP_BINARY,$temp.'/invoke.php',$file,base64_encode(json_encode(['server'=>$server,'post'=>$post]))],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);check(proc_close($p)===0,'Child failed: '.$err);
    preg_match('/HTTP_STATUS=(\d+)/',$err,$m);
    return ['out'=>$out,'status'=>(int)($m[1]??200)];
}
$local=['REQUEST_METHOD'=>'POST','HTTPS'=>'on','REMOTE_ADDR'=>'10.0.0.2'];
$page=invoke($temp,'link.php',$local,['csrf'=>str_repeat('9',64)]);
check(str_contains($page['out'],'data:image/svg+xml;base64,'),'Browser must render a local QR');
check(preg_match('/<textarea readonly>(.*?)<\/textarea>/s',$page['out'],$m)===1,'Private copy fallback');
$created=json_decode(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8'),true,512,JSON_THROW_ON_ERROR);
check($created['gateway']==='https://gateway.example' && $created['unit_name']==='Laptop' && !isset($created['token']),'QR holds short-lived handoff, not app token');
$stored=$db->query('SELECT codeHash FROM unitPairCode')->fetch_assoc();
check($stored['codeHash']===hash('sha256',$created['code']),'Code stored only as hash');
$local['REMOTE_ADDR']='100.68.1.2';
check(!str_contains(invoke($temp,'link.php',$local,['csrf'=>str_repeat('9',64)])['out'],'data:image/svg+xml;base64,'),'Overlay must not receive QR');
$local['REMOTE_ADDR']='10.0.0.2';unset($local['HTTPS']);
check(invoke($temp,'link.php',$local)['status']===303,'HTTP page redirects before generating secrets');
$server=['REQUEST_METHOD'=>'POST','HTTPS'=>'on','REMOTE_ADDR'=>'100.68.1.2'];
$code=str_repeat('c',64);$hash=hash('sha256',$code);
function seed(mysqli $db,string $hash,string $expiry='NOW()+INTERVAL 5 MINUTE'): void {
    $db->query("DELETE FROM unitPairCode");
    $db->query("INSERT INTO unitPairCode(codeHash,unitId,ownerId,peerIp,expires) VALUES('$hash',1,7,INET_ATON('10.0.0.2'),$expiry)");
}
$post=['code'=>$code,'client_id'=>str_repeat('f',32),'ticket'=>str_repeat('d',64)];
seed($db,$hash);
$r=invoke($temp,'script/unitPairCode.php',$server,$post); $json=json_decode($r['out'],true,512,JSON_THROW_ON_ERROR);
check($r['status']===201 && $json['scope']==='single_unit_read_only' && $json['unit']['unitId']===1,'Correct unit and scope required');
check((int)$db->query('SELECT COUNT(*) n FROM unitAppToken')->fetch_assoc()['n']===1,'One credential issued');
check($db->query('SELECT tokenHash FROM unitAppToken')->fetch_assoc()['tokenHash']===hash('sha256',$json['token']),'Only credential hash stored');
$r=invoke($temp,'script/unitPairCode.php',$server,$post);check($r['status']===410,'Used code must fail');
seed($db,$hash,'NOW()-INTERVAL 1 SECOND');check(invoke($temp,'script/unitPairCode.php',$server,$post)['status']===410,'Expired code must fail');
seed($db,$hash);$db->query('UPDATE unit SET ownerId=8');check(invoke($temp,'script/unitPairCode.php',$server,$post)['status']===410,'Owner change must invalidate');$db->query('UPDATE unit SET ownerId=7');
$plain=$server;unset($plain['HTTPS']);check(invoke($temp,'script/unitPairCode.php',$plain,$post)['status']===503,'Plaintext redemption must fail');
$bad=$post;$bad['ticket']='invalid';check(invoke($temp,'script/unitPairCode.php',$server,$bad)['status']===503,'Account proof required');
require $temp.'/script/unitLinkCommon.php';
check(unitAtLocalPeer($db,'10.0.0.2')['hostname']==='Laptop','Fresh LAN attribution');
try {unitAtLocalPeer($db,'100.68.1.2');check(false,'Overlay must not be attributed');}catch(UnitLinkException $e){}
$db->query('UPDATE unit SET lastSeen=NOW()-INTERVAL 20 MINUTE');
try {unitAtLocalPeer($db,'10.0.0.2');check(false,'Stale identity must fail');}catch(UnitLinkException $e){}
$db->query('UPDATE unit SET lastSeen=NOW()');
$db->query("INSERT INTO unit VALUES(2,9,INET_ATON('10.0.0.2'),NOW(),'Other')");
try {unitAtLocalPeer($db,'10.0.0.2');check(false,'Ambiguous identity must fail');}catch(UnitLinkException $e){}
foreach(glob($temp.'/script/*') as $f)unlink($f);rmdir($temp.'/script');foreach(glob($temp.'/*') as $f)unlink($f);rmdir($temp);
$db->query('DROP DATABASE tarasec_qr_test');
echo "QR expiry, single-use, account proof, scope, owner and LAN attribution checks passed\n";
