<?php
require_once __DIR__ . '/../html/script/webPokeCoordinatorLib.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function denied(callable $call, string $label) { try { $call(); } catch (DomainException $e) { return; } throw new RuntimeException($label); }
$gateway='100.68.165.190'; $newGateway='100.100.10.20';
$config=['ALLOW_ANY_GATEWAY'=>'YES','ALLOWED_GATEWAYS'=>$gateway];
foreach(webPokeNodes() as $id=>$node) $config['KEY_'.strtoupper($id)]=bin2hex(random_bytes(32));
check(webPokeAllowed($config,$newGateway), 'YES admits a new overlay gateway');
$closed=$config; $closed['ALLOW_ANY_GATEWAY']='NO';
check(!webPokeAllowed($closed,$newGateway) && webPokeAllowed($closed,$gateway), 'NO admits only exact listed gateway');
check(!webPokeAllowed(['ALLOW_ANY_GATEWAY'=>'yes','ALLOWED_GATEWAYS'=>$gateway],$gateway), 'Invalid policy fails closed');
check(!webPokeAllowed([], $gateway), 'Missing policy fails closed');
check(!webPokeAllowed($config, '127.0.0.1') && !webPokeAllowed($config,'example.com'), 'Only literal NetBird peer permitted');
check(!webPokeToken('../escape') && !webPokeToken(str_repeat('a',64)."\n"), 'Session format rejects traversal/trailing data');
$registered=fn($ip)=>in_array($ip,[$gateway,$newGateway],true);
$registrations=[]; $calls=0; $visitor=['owner_id'=>17,'unit_id'=>20];
$rpc=function($ip,$endpoint,$input) use (&$registrations,&$calls,&$visitor,$config) {
    ++$calls;
    if($endpoint==='webPokeGateway.php') return ['ok'=>true,'origin'=>'http://10.100.0.1','device_key'=>hash('sha256','10.100.0.150')] + $visitor;
    $nodeId=array_search($ip,array_column(webPokeNodes(),'address','name'),true);
    $id=array_keys(array_filter(webPokeNodes(),fn($node)=>$node['address']===$ip))[0];
    $message=webPokeVerify($input,$config['KEY_'.strtoupper($id)]);
    check($message['action']==='register' && $message['node_id']===$id, 'DB registers the selected receiver');
    $registrations[$id]=$message;
    return ['ok'=>true];
};
$proof=bin2hex(random_bytes(32));
denied(fn()=>webPokeCoordinator($closed,$newGateway,['action'=>'start','proof'=>$proof],$rpc,$registered),'Unapproved gateway must be denied');
check($calls===0, 'Deny precedes callback or state lookup');
$session=webPokeCoordinator($config,$newGateway,['action'=>'start','proof'=>$proof],$rpc,$registered);
check(count($registrations)===5 && $session['gateway_ip']===$newGateway && $session['unit_id']===20 && $session['owner_id']===17,'Dynamic gateway and stable unit registered centrally');
foreach($registrations as $registration) check($registration['gateway_ip']===$newGateway && $registration['origin']==='http://10.100.0.1','Receiver has dynamic route/origin');
$id=$session['session_id']; $control=$session['control_token'];
$status=['action'=>'status','session_id'=>$id,'control_token'=>$control];
$before=webPokeRecord('central',$id);
$result=webPokeCoordinator($config,$newGateway,$status,$rpc,$registered);
check($result['receivers']['roquefort']['state']==='unknown', 'No invented clean baseline');
check(webPokeRecord('central',$id)===$before,'Status polling never changes receiver observations');
denied(fn()=>webPokeCoordinator($config,$gateway,$status,$rpc,$registered),'Cannot use another gateway session');
$bad=$status; $bad['control_token']=bin2hex(random_bytes(32));
denied(fn()=>webPokeCoordinator($config,$newGateway,$bad,$rpc,$registered),'Guessed control capability cannot read state');
$nodeId='roquefort'; $node=webPokeNodes()[$nodeId]; $key=$config['KEY_ROQUEFORT'];
$receiverMessage=function($action,$extra=[]) use ($key,$id,$nodeId) {
    return ['action'=>'receiver','node_id'=>$nodeId]+webPokeSeal(['action'=>$action,'node_id'=>$nodeId,'session_id'=>$id]+$extra,$key);
};
$clean=webPokeEvidence(['senderIp'=>$newGateway,'trafficSeverity'=>0,'trafficSecondsSince'=>0],$newGateway,$newGateway);
$red=webPokeEvidence(['senderIp'=>$newGateway,'trafficSeverity'=>3,'trafficSecondsSince'=>0],$newGateway,$newGateway);
webPokeCoordinator($config,$node['address'],$receiverMessage('report',['observation'=>$clean]),$rpc,$registered);
$read=webPokeCoordinator($config,$newGateway,$status,$rpc,$registered);
check($read['receivers']['roquefort']['state']==='clean','Receiver report records actual clean observation');
webPokeCoordinator($config,$node['address'],$receiverMessage('report',['observation'=>$red]),$rpc,$registered);
$read=webPokeCoordinator($config,$newGateway,$status,$rpc,$registered);
check($read['receivers']['roquefort']['state']==='infected' && $read['receivers']['camembert']['state']==='unknown','Only reporting receiver changes');
denied(fn()=>webPokeCoordinator($closed,$newGateway,$status,$rpc,$registered),'NO revokes existing gateway status');
denied(fn()=>webPokeCoordinator($closed,$newGateway,['action'=>'authorize','session_id'=>$id,'control_token'=>$control],$rpc,$registered),'NO revokes controls');
denied(fn()=>webPokeCoordinator($closed,$node['address'],$receiverMessage('validate'),$rpc,$registered),'NO revokes existing direct Pokes');
denied(fn()=>webPokeCoordinator($closed,$node['address'],$receiverMessage('report',['observation'=>$clean]),$rpc,$registered),'NO rejects reports arriving after policy switch');
$access=['action'=>'receiver','node_id'=>$nodeId]+webPokeSeal(['action'=>'access','node_id'=>$nodeId,'gateway_ip'=>$newGateway],$key);
denied(fn()=>webPokeCoordinator($closed,$node['address'],$access,$rpc,$registered),'NO also closes old anonymous status API paths');
denied(fn()=>webPokeCoordinator($config,$newGateway,['action'=>'start','proof'=>$proof],$rpc,fn($ip)=>false),'YES still requires a registered gateway');
$packet=webPokeSeal(['action'=>'test'],$key); webPokeVerify($packet,$key);
denied(fn()=>webPokeVerify($packet,$key),'Signed replay is rejected');
$packet=webPokeSeal(['action'=>'test'],$key); $packet['payload'].='x';
denied(fn()=>webPokeVerify($packet,$key),'Tampered DB message rejected');
denied(fn()=>webPokeVerify(webPokeSeal(['action'=>'test'],$key),bin2hex(random_bytes(32))),'Wrong receiver key rejected');
$stale=['action'=>'test','issued_at'=>time()-60,'nonce'=>bin2hex(random_bytes(32))];
$payload=base64_encode(json_encode($stale));
denied(fn()=>webPokeVerify(['payload'=>$payload,'signature'=>hash_hmac('sha256',$payload,$key)],$key),'Stale signed message rejected');
foreach([-1,3,100] as $age) check(webPokeEvidence(['senderIp'=>$newGateway,'trafficSeverity'=>3,'trafficSecondsSince'=>$age],$newGateway,$newGateway)['state']==='unknown','Stale/missing tag stays unknown');
check(webPokeEvidence(['senderIp'=>$gateway,'trafficSeverity'=>3,'trafficSecondsSince'=>0],$gateway,$newGateway)['state']==='unknown','Wrong route cannot pass');
check(webPokeEvidence(['senderIp'=>$newGateway,'trafficSecondsSince'=>0],$newGateway,$newGateway)['state']==='unknown','Missing severity cannot become green');
webPokeRecord('central',$id,function($row){$row['expires']=time()-1;return $row;});
denied(fn()=>webPokeCoordinator($config,$newGateway,$status,$rpc,$registered),'Expired sessions cannot disclose state');
check(!array_intersect(['tomato','squash','audi','porsche'],array_keys(webPokeNodes())),'Excluded topology');
echo "DB policy, registered gateways, dynamic receiver enrollment, session isolation, legacy policy, signed messages, revocation, traffic evidence and expiry passed.\n";

check(webPokeIsLocal('10.100.0.150','10.100.0.1','255.255.255.0'),'Local client is a gateway request');
check(!webPokeIsLocal('100.68.165.190','10.100.0.1','255.255.255.0'),'Other gateway is a receiver request');
check(!webPokeIsLocal('10.100.1.2','10.100.0.1','255.255.255.0'),'Adjacent subnet is not local');
check(!webPokeIsLocal('10.100.0.150','','') && !webPokeIsLocal('10.100.0.150','10.100.0.1','0.0.0.0') && !webPokeIsLocal('10.100.0.150','10.100.0.1','255.0.255.0'),'Invalid subnet fails closed');
check(count(webPokeReceiversFor($gateway))===4 && !isset(webPokeReceiversFor($gateway)['standard']),'Gateway is excluded from its own receiver list');
check(isset(webPokeReceiversFor('100.68.176.110')['standard']) && !isset(webPokeReceiversFor('100.68.176.110')['roquefort']),'Standard serves units arriving through Roquefort');
$detected=webPokeDetectNode(['is_db'=>1,'db_ip'=>'100.68.126.0'],['100.68.126.0']);
check($detected['is_db'] && $detected['node_id']==='dbserver1','Detect central DB');
$detected=webPokeDetectNode(['is_db'=>0,'db_ip'=>'100.68.126.0'],['100.68.176.110']);
check(!$detected['is_db'] && $detected['node_id']==='roquefort','Detect receiver on a gateway-capable node');
$detected=webPokeDetectNode(['is_db'=>0,'db_ip'=>'100.68.126.0'],[$newGateway]);
check($detected['node_id']==='' && !$detected['is_db'],'New unlisted gateway has no implicit receiver privilege');
if (function_exists('sodium_crypto_box_keypair')) {
    $pair=sodium_crypto_box_keypair(); $proof=bin2hex(random_bytes(32));
    $pending=['db_ip'=>'100.68.126.0','proof_hash'=>hash('sha256',$proof),'expires'=>time()+60,'public_key'=>bin2hex(sodium_crypto_box_publickey($pair))];
    $enrollRpc=fn($ip,$endpoint,$body)=>webPokeBootstrapProof($pending,'100.68.126.0',$body['proof']);
    $enrolled=webPokeEnrollReceiver($config,$gateway,['proof'=>$proof],$enrollRpc);
    $plain=sodium_crypto_box_seal_open(base64_decode($enrolled['sealed_config']),$pair);
    $credentials=json_decode($plain,true);
    check($credentials['NODE_ID']==='standard' && $credentials['AUTH_KEY']===$config['KEY_STANDARD'],'Root enrollment decrypts only its own receiver key');
    check(sodium_crypto_box_seal_open(base64_decode($enrolled['sealed_config']),sodium_crypto_box_keypair())===false,'Another browser/process cannot decrypt credentials');
    denied(fn()=>webPokeBootstrapProof($pending,$gateway,$proof),'Bootstrap trusts only configured DB peer');
    denied(fn()=>webPokeBootstrapProof($pending,'100.68.126.0',bin2hex(random_bytes(32))),'Browser cannot invent root bootstrap proof');
    $pending['expires']=time()-1;
    denied(fn()=>webPokeBootstrapProof($pending,'100.68.126.0',$proof),'Expired root bootstrap rejected');
    denied(fn()=>webPokeEnrollReceiver($config,$newGateway,['proof'=>$proof],$enrollRpc),'Unlisted gateway cannot enroll as receiver');
}
echo "Automatic role detection, subnet routing, dynamic receiver lists and encrypted administrator enrollment passed.\n";

// Gateway policy governs admission; missing visitor registration must not block it.
foreach ([['owner_id'=>0,'unit_id'=>0], ['owner_id'=>0,'unit_id'=>20]] as $visitor) {
    $guest=webPokeCoordinator($config,$newGateway,['action'=>'start','proof'=>$proof],$rpc,$registered);
    check($guest['owner_id']===$visitor['owner_id'] && $guest['unit_id']===$visitor['unit_id'], 'Visitor without owner/device registration admitted');
    $guestStatus=['action'=>'status','session_id'=>$guest['session_id'],'control_token'=>$guest['control_token']];
    check(webPokeCoordinator($config,$newGateway,$guestStatus,$rpc,$registered)['ok'], 'Guest session can read authorized status');
    denied(fn()=>webPokeCoordinator($closed,$newGateway,$guestStatus,$rpc,$registered),'NO still revokes guest sessions');
    denied(fn()=>webPokeCoordinator($closed,$newGateway,['action'=>'start','proof'=>$proof],$rpc,$registered),'NO still denies unlisted guest gateways');
}
echo "Unregistered visitors admitted under YES; NO policy still enforced.\n";

// One active web demonstration per gateway-observed device, including guests.
$visitor=['owner_id'=>0,'unit_id'=>0];
$first=webPokeCoordinator($config,$newGateway,['action'=>'start','proof'=>$proof],$rpc,$registered);
$activate=fn($s)=>['action'=>'replace','session_id'=>$s['session_id'],'control_token'=>$s['control_token']];
$firstStatus=['action'=>'status','session_id'=>$first['session_id'],'control_token'=>$first['control_token']];
webPokeCoordinator($config,$newGateway,$activate($first),$rpc,$registered);
$second=webPokeCoordinator($config,$newGateway,['action'=>'start','proof'=>$proof],$rpc,$registered);
$closedDevices=[];
$closeOwn=function($gateway,$unit) use (&$closedDevices) { $closedDevices[]=[$gateway,$unit]; return 0; };
webPokeCoordinator($config,$newGateway,$activate($second),$rpc,$registered,$closeOwn);
check($closedDevices===[[$newGateway,0]],'Device replacement uses gateway-verified identity');
denied(fn()=>webPokeCoordinator($config,$newGateway,$firstStatus,$rpc,$registered),'Older guest web session revoked');
$oldReceiver=['action'=>'receiver','node_id'=>$nodeId]+webPokeSeal(['action'=>'validate','node_id'=>$nodeId,'session_id'=>$first['session_id']],$key);
denied(fn()=>webPokeCoordinator($config,$node['address'],$oldReceiver,$rpc,$registered),'Older Poke capability revoked at receiver validation');
denied(fn()=>webPokeCoordinator($config,$newGateway,$activate($first),$rpc,$registered),'Replaced session cannot take over again');
$secondStatus=['action'=>'status','session_id'=>$second['session_id'],'control_token'=>$second['control_token']];
check(webPokeCoordinator($config,$newGateway,$secondStatus,$rpc,$registered)['ok'],'Replacement remains active');
$visitor=['owner_id'=>0,'unit_id'=>99];
$other=webPokeCoordinator($config,$newGateway,['action'=>'start','proof'=>$proof],$rpc,$registered);
webPokeCoordinator($config,$newGateway,$activate($other),$rpc,$registered);
check(webPokeCoordinator($config,$newGateway,$secondStatus,$rpc,$registered)['ok'],'Another unit on the same gateway is unaffected');
echo "Device replacement revokes older status/controls/Pokes while preserving other units.\n";
