<?php
// Caller-scoped, read-only session discovery across app and browser.
ini_set('display_errors','0');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
include '../dbfunc.php';
include '../taraLib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
function deviceReply(int $status,array $data): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES); exit; }
function deviceDbRequest(string $ip,array $params,bool $post=false): array {
    $url='http://'.$ip.'/script/appDemoAssistance.php';
    $options=['timeout'=>4,'ignore_errors'=>true,'follow_location'=>0];
    if($post) { $options['method']='POST'; $options['header']='Content-Type: application/x-www-form-urlencoded'; $options['content']=http_build_query($params); }
    else $url.='?'.http_build_query($params);
    $raw=file_get_contents($url,false,stream_context_create(['http'=>$options]));
    $data=$raw===false?null:json_decode($raw,true);
    if(!is_array($data)||empty($data['ok'])) throw new RuntimeException('demo_status_unavailable');
    return $data;
}
try {
    $client=getSenderIp();
    if(!filter_var($client,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) deviceReply(400,['ok'=>false,'error'=>'invalid_client']);
    $c=getConnection();
    // Idempotent bootstrap for web-only upgrades. No tokens are stored here.
    $c->query("CREATE TABLE IF NOT EXISTS demoDeviceSession (clientIp VARCHAR(45) PRIMARY KEY,sessionId INT UNSIGNED NOT NULL,participantId INT UNSIGNED NOT NULL,registeredAt DATETIME NOT NULL,sessionJson MEDIUMTEXT NOT NULL)");
    $row=$c->query('SELECT INET_NTOA(globalDb1ip) db FROM setup LIMIT 1')->fetch_assoc();
    $db=(string)($row['db']??'');
    if(!filter_var($db,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) deviceReply(503,['ok'=>false,'error'=>'db_not_configured']);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        $sid=filter_var($_POST['session_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $token=(string)($_POST['participant_token']??'');
        if(!$sid||!preg_match('/^[a-f0-9]{64}$/D',$token)) deviceReply(400,['ok'=>false,'error'=>'invalid_registration']);
        // The DB authenticates ownership without recording a heartbeat.
        $data=deviceDbRequest($db,['action'=>'inspect_participant','session_id'=>$sid,'participant_token'=>$token],true);
        $pid=(int)$data['participant_id'];$json=json_encode($data['session']);
        $s=$c->prepare('INSERT INTO demoDeviceSession VALUES(?,?,?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE sessionId=VALUES(sessionId),participantId=VALUES(participantId),registeredAt=UTC_TIMESTAMP(),sessionJson=VALUES(sessionJson)');
        $s->bind_param('siis',$client,$sid,$pid,$json);$s->execute();
        deviceReply(200,['ok'=>true,'registered'=>true,'client_ip'=>$client]);
    }
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET') deviceReply(405,['ok'=>false,'error'=>'method_not_allowed']);
    $s=$c->prepare('SELECT * FROM demoDeviceSession WHERE clientIp=? AND registeredAt>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)');
    $s->bind_param('s',$client);$s->execute();$row=$s->get_result()->fetch_assoc();
    if(!$row) deviceReply(200,['ok'=>true,'client_ip'=>$client,'demo'=>null]);
    $session=json_decode($row['sessionJson'],true);$fresh=false;
    try { $data=deviceDbRequest($db,['action'=>'status','session_id'=>$row['sessionId']]);$session=$data['session'];$fresh=true; } catch(Throwable $e) { /* Cached identity remains useful during containment or DB failure. */ }
    $participant=null;foreach(($session['participants']??[]) as $p) if((int)$p['participant_id']===(int)$row['participantId']) $participant=$p;
    if(!$participant||$participant['decision']==='left'||$session['state']==='closed') deviceReply(200,['ok'=>true,'client_ip'=>$client,'demo'=>null]);
    deviceReply(200,['ok'=>true,'client_ip'=>$client,'demo'=>['type'=>'demo3','session_id'=>(int)$row['sessionId'],'participant_id'=>(int)$row['participantId'],'db_endpoint'=>'http://'.$db,'fresh'=>$fresh,'registered_at'=>$row['registeredAt'],'session'=>$session]]);
} catch(Throwable $e) { error_log('Demo device discovery unavailable');deviceReply(503,['ok'=>false,'error'=>'device_discovery_unavailable']); }
