<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit;
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../html/dbfunc.php';
$configPath=$argv[1]??'/etc/tarasec/partner-restrictions.json';
$cfg=json_decode((string)file_get_contents($configPath),true,512,JSON_THROW_ON_ERROR);
$base=rtrim((string)$cfg['db_url'],'/');$token=(string)$cfg['node_token'];
if (!filter_var($base,FILTER_VALIDATE_URL) || !in_array(parse_url($base,PHP_URL_SCHEME),['http','https'],true) || strlen($token)<32) throw new RuntimeException('Invalid receiver configuration');
function command(array $args,bool $allowFailure=false): int {
    $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($p)) throw new RuntimeException('Cannot run firewall command');
    fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);
    $error=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($p);
    if ($code && !$allowFailure) throw new RuntimeException(implode(' ',array_slice($args,0,3)).': '.$error);
    return $code;
}
function exchange(string $base,string $token,?array $body=null): array {
    $headers="X-TaraSec-Node-Token: $token\r\nAccept: application/json\r\n";
    $options=['method'=>$body===null?'GET':'POST','header'=>$headers,'timeout'=>5,'follow_location'=>0,'ignore_errors'=>true];
    if ($body!==null) {$options['header'].="Content-Type: application/json\r\n";$options['content']=json_encode($body);}
    $raw=@file_get_contents($base.'/script/partnerRestrictions.php',false,stream_context_create(['http'=>$options]));
    if ($raw===false || !preg_match('/^HTTP\/\S+ 200\b/',($http_response_header??[])[0]??'')) throw new RuntimeException('DB restriction exchange failed');
    $result=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if (empty($result['ok'])) throw new RuntimeException('DB did not acknowledge restriction exchange');
    return $result;
}
$lock=fopen('/run/tarasec-partner-restrictions.lock','c');
if (!$lock||!flock($lock,LOCK_EX|LOCK_NB)) exit;
$feed=exchange($base,$token);$rows=$feed['restrictions']??null;
if (!is_array($rows)) throw new RuntimeException('Missing restriction snapshot');
$active=[];
foreach($rows as $r) {
    if (!preg_match('/^[a-f0-9]{32}$/D',(string)$r['session_id']) || !filter_var($r['source_ip'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)
        || !is_numeric($r['ttl']) || (int)$r['ttl']<0 || (int)$r['ttl']>300) throw new RuntimeException('Invalid restriction');
    if ((int)$r['ttl']>0) $active[$r['source_ip']]=max($active[$r['source_ip']]??0,(int)$r['ttl']);
}
// Kernel timeout continues expiring restrictions if this worker or DB goes down.
command(['ipset','create','tarasec_partner_block','hash:ip','family','inet','timeout','300','-exist']);
foreach(['INPUT','FORWARD'] as $hook) {
    $rule=['iptables','-w','5','-C',$hook,'-m','set','--match-set','tarasec_partner_block','src','-j','DROP'];
    if (command($rule,true)!==0) {
        $rule[3]='-I';array_splice($rule,5,0,['1']);command($rule);
    }
}
foreach($active as $ip=>$ttl) command(['ipset','add','tarasec_partner_block',$ip,'timeout',(string)$ttl,'-exist']);
$db=getConnection();
foreach($rows as $r) {
    $id=$r['session_id'];$ip=$r['source_ip'];$ttl=(int)$r['ttl'];
    try {
        if ($ttl>0) {
            command(['ipset','test','tarasec_partner_block',$ip]);
            foreach(['INPUT','FORWARD'] as $hook) command(['iptables','-w','5','-C',$hook,'-m','set','--match-set','tarasec_partner_block','src','-j','DROP']);
            // Reflect DB-owned restrictions without overwriting manual listings.
            $q=$db->prepare("INSERT INTO colorListings(ip,color,active,handled,listingSource,expiresAt)
                VALUES(INET_ATON(?),'black',b'1',NULL,'partner_db',DATE_ADD(NOW(),INTERVAL ? SECOND))
                ON DUPLICATE KEY UPDATE color=IF(listingSource='partner_db','black',color),
                active=IF(listingSource='partner_db',b'1',active),
                expiresAt=IF(listingSource='partner_db',VALUES(expiresAt),expiresAt)");
            $q->bind_param('si',$ip,$ttl);$q->execute();$q->close();
            exchange($base,$token,['session_id'=>$id,'state'=>'applied','message'=>'Verified kernel ipset membership and INPUT/FORWARD drop rules; independent of tarakernel.']);
        } else {
            if (!isset($active[$ip])) {
                command(['ipset','del','tarasec_partner_block',$ip],true);
                if (command(['ipset','test','tarasec_partner_block',$ip],true)===0) throw new RuntimeException('Restriction remains in kernel');
                $q=$db->prepare("DELETE FROM colorListings WHERE ip=INET_ATON(?) AND listingSource='partner_db'");
                $q->bind_param('s',$ip);$q->execute();$q->close();
            }
            exchange($base,$token,['session_id'=>$id,'state'=>'released','message'=>isset($active[$ip])?'This session expired; another active restriction still applies.':'Demo-owned kernel restriction removed. Manual policy unchanged.']);
        }
    } catch(Throwable $e) {exchange($base,$token,['session_id'=>$id,'state'=>'error','message'=>substr($e->getMessage(),0,255)]);}
}
$db->query("DELETE FROM colorListings WHERE listingSource='partner_db' AND expiresAt<=NOW()");
